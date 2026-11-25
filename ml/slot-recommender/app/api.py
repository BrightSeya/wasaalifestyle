"""Flask service for slot recommendations.

The model and completion-rate lookup are loaded once at startup. If either file is
missing (e.g. before training has been run), the app still starts so /health can report
the real load state instead of crashing.
"""

import hmac
import json
import math
import os
import re
import sys
import threading
from pathlib import Path

import joblib
import pandas as pd
from flask import Flask, jsonify, request

ROOT = Path(__file__).resolve().parents[1]
MODEL_PATH = ROOT / "models" / "rf_model.joblib"
LOOKUP_PATH = ROOT / "data" / "processed" / "completion_rate_lookup.csv"
MODEL_CARD_PATH = ROOT / "models" / "model_card.json"

sys.path.append(str(ROOT / "src"))
from atus_category_map import CATEGORIES  # noqa: E402
from candidates import REQUEST_FIELDS, top_k_hours  # noqa: E402
from personalization import fit_personal_model, personal_model_features  # noqa: E402
import train  # noqa: E402

TOP_K = 3
DEFAULT_SUITABILITY_THRESHOLD = 0.5
MAX_PERSONAL_HISTORY = 2000
suitability_threshold = DEFAULT_SUITABILITY_THRESHOLD


app = Flask(__name__)

model = None
lookup = None
MODEL_LOCK = threading.RLock()
RETRAIN_LOCK = threading.Lock()

try:
    model = joblib.load(MODEL_PATH)
    lookup = pd.read_csv(LOOKUP_PATH)
    if MODEL_CARD_PATH.is_file():
        try:
            card_threshold = json.loads(MODEL_CARD_PATH.read_text(encoding="utf-8")).get("decision_threshold")
            if isinstance(card_threshold, (int, float)) and math.isfinite(card_threshold) and 0 <= card_threshold <= 1:
                suitability_threshold = float(card_threshold)
        except (OSError, ValueError, TypeError):
            pass
except FileNotFoundError:
    pass


@app.get("/health")
def health():
    with MODEL_LOCK:
        model_loaded = model is not None and lookup is not None
    return jsonify({"status": "ok", "model_loaded": model_loaded})


def validate_request(data):
    """Return an error message describing what is wrong, or None if the request is valid."""
    if not isinstance(data, dict):
        return "Request body must be a JSON object."

    missing = [field for field in REQUEST_FIELDS if field not in data]
    if missing:
        return f"Missing field(s): {', '.join(missing)}"

    if data["category"] not in CATEGORIES:
        return f"category must be one of: {', '.join(CATEGORIES)}"

    day_of_week = data["day_of_week"]
    if not isinstance(day_of_week, int) or isinstance(day_of_week, bool) or not (0 <= day_of_week <= 6):
        return "day_of_week must be an integer from 0 (Monday) to 6 (Sunday)"

    for field in ("duration_min", "schedule_density", "gap_since_last_same_category_hrs"):
        value = data[field]
        if not isinstance(value, (int, float)) or isinstance(value, bool):
            return f"{field} must be a number"

    if data["duration_min"] <= 0:
        return "duration_min must be a positive number of minutes"

    if data["schedule_density"] < 0:
        return "schedule_density must not be negative"

    return None


def validate_user_feedback(feedback):
    """Validate compact per-user outcome counts before they affect recommendations."""
    if feedback is None:
        return None
    if not isinstance(feedback, dict) or len(feedback) > 168:
        return "user_feedback must be an object with at most 168 weekday-hour bins."

    expected_fields = {"category_completed", "category_total", "other_completed", "other_total"}
    for key, counts in feedback.items():
        if not isinstance(key, str) or not re.fullmatch(r"[0-6]:(?:[0-9]|1[0-9]|2[0-3])", key):
            return "user_feedback contains an unsupported weekday-hour bin."
        if not isinstance(counts, dict) or set(counts) != expected_fields:
            return "user_feedback contains unsupported count fields."
        for value in counts.values():
            if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
                return "user_feedback counts must be finite numbers."
            if value < 0 or value > 1_000_000:
                return "user_feedback counts are outside the supported range."
        if counts["category_completed"] > counts["category_total"]:
            return "Category completions cannot exceed category outcomes."
        if counts["other_completed"] > counts["other_total"]:
            return "Other completions cannot exceed other outcomes."

    return None


def validate_personal_history(history):
    """Validate the current user's labeled examples before fitting an isolated model."""
    if history is None:
        return None
    if not isinstance(history, list) or len(history) > MAX_PERSONAL_HISTORY:
        return f"personal_history must be a list of at most {MAX_PERSONAL_HISTORY} outcomes."

    fields = {
        "category", "day_of_week", "hour", "duration_min", "schedule_density",
        "gap_since_last_same_category_hrs", "label", "weight", "occurred_at",
    }
    for example in history:
        if not isinstance(example, dict) or set(example) != fields:
            return "personal_history contains unsupported outcome fields."
        if example["category"] not in CATEGORIES:
            return "personal_history contains an unsupported category."
        for field, minimum, maximum in (
            ("day_of_week", 0, 6),
            ("hour", 0, 23),
            ("duration_min", 1, 1440),
            ("schedule_density", 0, 10000),
            ("gap_since_last_same_category_hrs", 0, 87600),
            ("label", 0, 1),
            ("occurred_at", 1, 4102444800),
        ):
            value = example[field]
            if isinstance(value, bool) or not isinstance(value, (int, float)):
                return f"personal_history {field} values must be numbers."
            if not math.isfinite(value) or value < minimum or value > maximum:
                return f"personal_history {field} values are outside the supported range."
            if field in {"day_of_week", "hour", "duration_min", "schedule_density", "label", "occurred_at"} and not isinstance(value, int):
                return f"personal_history {field} values must be integers."

        weight = example["weight"]
        if isinstance(weight, bool) or not isinstance(weight, (int, float)) or not math.isfinite(weight):
            return "personal_history weights must be finite numbers."
        if not 0 < weight <= 1:
            return "personal_history weights must be greater than 0 and at most 1."

    return None


def validate_excluded_hours(hours):
    if hours is None:
        return None
    if not isinstance(hours, list) or len(hours) > 24:
        return "exclude_hours must be a list of at most 24 hour values."
    if any(not isinstance(hour, int) or isinstance(hour, bool) or not 0 <= hour <= 23 for hour in hours):
        return "exclude_hours values must be integers from 0 to 23."
    return None


def validate_preference_hours(hours, field):
    if hours is None:
        return None
    if not isinstance(hours, list) or any(not isinstance(hour, int) or isinstance(hour, bool) or not 0 <= hour <= 23 for hour in hours):
        return f"{field} must be a list of hour values from 0 to 23."
    if len(set(hours)) != len(hours):
        return f"{field} must not contain duplicate hours."
    return None


@app.post("/predict")
def predict():
    with MODEL_LOCK:
        active_model, active_lookup, active_threshold = model, lookup, suitability_threshold

    if active_model is None or active_lookup is None:
        return jsonify({"error": "Model not loaded"}), 503

    data = request.get_json(silent=True)
    error = validate_request(data)
    if error:
        return jsonify({"error": error}), 400

    user_feedback = data.get("user_feedback", {})
    error = validate_user_feedback(user_feedback)
    if error:
        return jsonify({"error": error}), 400

    personal_history = data.get("personal_history", [])
    error = validate_personal_history(personal_history)
    if error:
        return jsonify({"error": error}), 400
    personal_history = personal_history or []

    excluded_hours = data.get("exclude_hours", [])
    error = validate_excluded_hours(excluded_hours)
    if error:
        return jsonify({"error": error}), 400

    available_hours = data.get("available_hours", list(range(24)))
    preferred_hours = data.get("preferred_hours", [])
    for field, hours in (("available_hours", available_hours), ("preferred_hours", preferred_hours)):
        error = validate_preference_hours(hours, field)
        if error:
            return jsonify({"error": error}), 400
    if not available_hours:
        return jsonify({"error": "No available time window is long enough for this activity on this day. Update availability or reduce the activity duration."}), 422

    requested_top_k = data.get("top_k", TOP_K)
    if not isinstance(requested_top_k, int) or isinstance(requested_top_k, bool) or not 1 <= requested_top_k <= 24:
        return jsonify({"error": "top_k must be an integer from 1 to 24."}), 400

    request_body = {field: data[field] for field in REQUEST_FIELDS}
    personal_model, personal_model_status = fit_personal_model(
        personal_history,
        active_lookup,
        active_model,
    )
    if personal_model is not None:
        score_fn = lambda df: personal_model.predict_proba(personal_model_features(df))[:, 1]  # noqa: E731
        user_feedback = {}
        prediction_threshold = personal_model_status["decision_threshold"]
        prediction_basis = "per_user_model"
    else:
        score_fn = lambda df: active_model.predict_proba(df)[:, 1]  # noqa: E731
        prediction_threshold = active_threshold
        prediction_basis = "atus_prior_plus_user_history" if user_feedback else "atus_cold_start"

    top = top_k_hours(
        request_body,
        active_lookup,
        score_fn,
        k=requested_top_k,
        user_feedback=user_feedback,
        excluded_hours=excluded_hours,
        available_hours=available_hours,
        preferred_hours=preferred_hours,
    )

    recommendations = [
        {
            "hour": int(row["hour"]),
            "confidence": round(float(row["score"]), 4),
            "suitable": bool(row["score"] >= prediction_threshold),
        }
        for row in top
    ]

    return jsonify({
        "category": data["category"],
        "day_of_week": data["day_of_week"],
        "prediction_basis": prediction_basis,
        "personal_model_status": personal_model_status["status"],
        "recommendations": recommendations,
    })


@app.post("/retrain")
def retrain():
    global model, lookup, suitability_threshold

    expected_key = os.environ.get("ML_RETRAIN_KEY")
    provided_key = request.headers.get("X-API-Key", "")

    if (
        not expected_key
        or not provided_key
        or not hmac.compare_digest(provided_key.encode("utf-8"), expected_key.encode("utf-8"))
    ):
        return jsonify({"error": "Unauthorized"}), 401

    try:
        with RETRAIN_LOCK:
            train.main()
            refreshed_model = joblib.load(MODEL_PATH)
            refreshed_lookup = pd.read_csv(LOOKUP_PATH)
            model_card = json.loads(MODEL_CARD_PATH.read_text(encoding="utf-8"))
            refreshed_threshold = model_card.get("decision_threshold", DEFAULT_SUITABILITY_THRESHOLD)
            if not isinstance(refreshed_threshold, (int, float)) or not math.isfinite(refreshed_threshold) or not 0 <= refreshed_threshold <= 1:
                refreshed_threshold = DEFAULT_SUITABILITY_THRESHOLD
            summary = {
                "cv_f1": model_card["cv_f1"],
                "best_params": model_card["best_params"],
                "decision_threshold": refreshed_threshold,
            }

            with MODEL_LOCK:
                model, lookup, suitability_threshold = refreshed_model, refreshed_lookup, float(refreshed_threshold)
    except Exception:
        app.logger.exception("Slot recommender retraining failed")
        return jsonify({
            "error": "Retraining failed; the previously loaded model remains active.",
        }), 503

    return jsonify({"status": "ok", "model_card": summary})


if __name__ == "__main__":
    app.run(port=5001, debug=False)


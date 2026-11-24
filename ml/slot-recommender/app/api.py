"""Flask service for slot recommendations and the optional private risk endpoint.

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
from typing import TypedDict

import joblib
import pandas as pd
from flask import Flask, jsonify, request

ROOT = Path(__file__).resolve().parents[1]
MODEL_PATH = ROOT / "models" / "rf_model.joblib"
LOOKUP_PATH = ROOT / "data" / "processed" / "completion_rate_lookup.csv"
MODEL_CARD_PATH = ROOT / "models" / "model_card.json"

sys.path.append(str(ROOT / "src"))
NOSHOW_SRC = ROOT.parent / "no-show" / "src"
sys.path.append(str(NOSHOW_SRC))
from atus_category_map import CATEGORIES  # noqa: E402
from candidates import REQUEST_FIELDS, top_k_hours  # noqa: E402
from train_model import FEATURES as RISK_FEATURES  # noqa: E402
from train_model import explain_factors, predict_probability  # noqa: E402
import train  # noqa: E402

TOP_K = 3
DEFAULT_SUITABILITY_THRESHOLD = 0.5
suitability_threshold = DEFAULT_SUITABILITY_THRESHOLD


class CommitmentRiskRequest(TypedDict):
    category: str
    priority: str
    weekday: int
    scheduled_hour: int
    duration_minutes: float
    lead_hours: float
    prior_labeled_count: int
    prior_missed_rate: float | None
    prior_same_category_labeled_count: int
    prior_same_category_missed_rate: float | None

app = Flask(__name__)

RISK_MODEL_PATH = Path(
    os.environ.get(
        "WASAA_RISK_MODEL_PATH",
        ROOT.parent / "no-show" / "models" / "commitment_risk.joblib",
    )
)
RISK_MODEL_CARD_PATH = Path(
    os.environ.get(
        "WASAA_RISK_MODEL_CARD_PATH",
        ROOT.parent / "no-show" / "models" / "model_card.json",
    )
)
risk_model_enabled = os.environ.get("WASAA_RISK_MODEL_ENABLED", "true").strip().lower() == "true"
risk_model_bundle = None
risk_model_card = None
allow_synthetic_risk_model = os.environ.get("WASAA_RISK_ALLOW_SYNTHETIC_MODEL", "true").strip().lower() == "true"


def risk_artifact_supported(card, bundle, *, allow_synthetic=False):
    version = card.get("model_version") or card.get("git_commit") or card.get("evaluation_date_utc")
    common = (
        isinstance(card.get("model"), str)
        and bundle.get("features") == RISK_FEATURES
        and bundle.get("version") == version
    )
    if card.get("evidence_type") == "approved-real":
        return common
    return (
        allow_synthetic
        and card.get("evidence_type") == "synthetic-upstream"
        and bundle.get("model_type") == "upstream-synthetic-cold-start-v1"
        and common
    )

if risk_model_enabled and RISK_MODEL_PATH.is_file() and RISK_MODEL_CARD_PATH.is_file():
    try:
        candidate_card = json.loads(RISK_MODEL_CARD_PATH.read_text(encoding="utf-8"))
        candidate_bundle = joblib.load(RISK_MODEL_PATH)
        if risk_artifact_supported(candidate_card, candidate_bundle, allow_synthetic=allow_synthetic_risk_model):
            risk_model_bundle = candidate_bundle
            risk_model_card = candidate_card
    except Exception:
        # Keep the slot recommender available if the optional risk model cannot load.
        pass

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


def validate_excluded_hours(hours):
    if hours is None:
        return None
    if not isinstance(hours, list) or len(hours) > 24:
        return "exclude_hours must be a list of at most 24 hour values."
    if any(not isinstance(hour, int) or isinstance(hour, bool) or not 0 <= hour <= 23 for hour in hours):
        return "exclude_hours values must be integers from 0 to 23."
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

    excluded_hours = data.get("exclude_hours", [])
    error = validate_excluded_hours(excluded_hours)
    if error:
        return jsonify({"error": error}), 400

    request_body = {field: data[field] for field in REQUEST_FIELDS}
    score_fn = lambda df: active_model.predict_proba(df)[:, 1]  # noqa: E731
    top = top_k_hours(request_body, active_lookup, score_fn, k=TOP_K, user_feedback=user_feedback, excluded_hours=excluded_hours)

    recommendations = [
        {
            "hour": int(row["hour"]),
            "confidence": round(float(row["score"]), 4),
            "suitable": bool(row["score"] >= active_threshold),
        }
        for row in top
    ]

    return jsonify({
        "category": data["category"],
        "day_of_week": data["day_of_week"],
        "prediction_basis": "atus_prior_plus_user_history" if user_feedback else "atus_cold_start",
        "recommendations": recommendations,
    })


def validate_risk_request(data: object) -> str | None:
    """Return a safe validation error, or None when the approved feature object is valid."""
    if not isinstance(data, dict):
        return "Request body must be a JSON object."

    missing = set(RISK_FEATURES) - set(data)
    unexpected = set(data) - set(RISK_FEATURES)
    if missing:
        return "Request is missing required approved feature fields."
    if unexpected:
        return "Request contains unsupported fields."

    if not isinstance(data["category"], str) or data["category"] not in CATEGORIES:
        return "category is not supported."
    if not isinstance(data["priority"], str) or data["priority"] not in {"low", "medium", "high"}:
        return "priority is not supported."

    for field, minimum, maximum in (("weekday", 0, 6), ("scheduled_hour", 0, 23)):
        value = data[field]
        if not isinstance(value, int) or isinstance(value, bool) or not minimum <= value <= maximum:
            return f"{field} is outside the supported range."

    for field in ("prior_labeled_count", "prior_same_category_labeled_count"):
        value = data[field]
        if not isinstance(value, int) or isinstance(value, bool) or not 0 <= value <= 1_000_000:
            return f"{field} must be a non-negative integer in the supported range."

    if data["prior_same_category_labeled_count"] > data["prior_labeled_count"]:
        return "Category history count cannot exceed total history count."

    for field, minimum, maximum in (
        ("duration_minutes", 1, 10_080),
        ("lead_hours", 0, 87_600),
    ):
        value = data[field]
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
            return f"{field} must be a finite number."
        if not minimum <= value <= maximum:
            return f"{field} is outside the supported range."

    for field, count_field in (
        ("prior_missed_rate", "prior_labeled_count"),
        ("prior_same_category_missed_rate", "prior_same_category_labeled_count"),
    ):
        value = data[field]
        if value is None:
            if data[count_field] != 0:
                return f"{field} must be supplied when its history count is non-zero."
            continue
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
            return f"{field} must be a finite number or null."
        if not 0 <= value <= 1 or data[count_field] == 0:
            return f"{field} must be between 0 and 1 and requires a non-zero history count."

    return None


@app.post("/noshow/predict")
def predict_commitment_risk():
    if not risk_model_enabled or risk_model_bundle is None or risk_model_card is None:
        return jsonify({"error": "Commitment-risk prediction is unavailable."}), 503

    data = request.get_json(silent=True)
    error = validate_risk_request(data)
    if error:
        return jsonify({"error": error}), 400

    # Use a fixed allow-list; no request IDs, text, or raw payload is passed to inference.
    feature_row: CommitmentRiskRequest = {field: data[field] for field in RISK_FEATURES}
    try:
        probability = float(predict_probability(risk_model_bundle, pd.DataFrame([feature_row]))[0])
        if not math.isfinite(probability) or not 0 <= probability <= 1:
            raise ValueError("Model returned an invalid probability")
        factors = explain_factors(risk_model_bundle, feature_row, limit=3)
    except Exception:
        app.logger.warning("Commitment-risk inference failed")
        return jsonify({"error": "Commitment-risk prediction is unavailable."}), 503

    return jsonify({
        "probability": round(probability, 4),
        "factors": factors,
        "model": {
            "name": risk_model_card.get("model"),
            "version": risk_model_card.get("model_version") or risk_model_card.get("git_commit") or risk_model_card.get("evaluation_date_utc"),
            "evidence_type": risk_model_card.get("evidence_type"),
            "prototype_only": risk_model_card.get("evidence_type") == "synthetic-upstream",
        },
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


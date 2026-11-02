"""Flask service hosting the trained slot-recommender Random Forest model.

The model and completion-rate lookup are loaded once at startup. If either file is
missing (e.g. before training has been run), the app still starts so /health can report
the real load state instead of crashing.
"""

import hmac
import json
import os
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
import train  # noqa: E402

TOP_K = 3
CONFIDENCE_THRESHOLD = 0.5

app = Flask(__name__)

model = None
lookup = None
MODEL_LOCK = threading.RLock()
RETRAIN_LOCK = threading.Lock()

try:
    model = joblib.load(MODEL_PATH)
    lookup = pd.read_csv(LOOKUP_PATH)
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


@app.post("/predict")
def predict():
    with MODEL_LOCK:
        active_model, active_lookup = model, lookup

    if active_model is None or active_lookup is None:
        return jsonify({"error": "Model not loaded"}), 503

    data = request.get_json(silent=True)
    error = validate_request(data)
    if error:
        return jsonify({"error": error}), 400

    request_body = {field: data[field] for field in REQUEST_FIELDS}
    score_fn = lambda df: active_model.predict_proba(df)[:, 1]  # noqa: E731
    top = top_k_hours(request_body, active_lookup, score_fn, k=TOP_K)

    recommendations = [
        {
            "hour": int(row["hour"]),
            "confidence": round(float(row["score"]), 4),
            "suitable": bool(row["score"] >= CONFIDENCE_THRESHOLD),
        }
        for row in top
    ]

    return jsonify({
        "category": data["category"],
        "day_of_week": data["day_of_week"],
        "recommendations": recommendations,
    })


@app.post("/retrain")
def retrain():
    global model, lookup

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
            summary = {
                "cv_f1": model_card["cv_f1"],
                "best_params": model_card["best_params"],
            }

            with MODEL_LOCK:
                model, lookup = refreshed_model, refreshed_lookup
    except Exception:
        app.logger.exception("Slot recommender retraining failed")
        return jsonify({
            "error": "Retraining failed; the previously loaded model remains active.",
        }), 503

    return jsonify({"status": "ok", "model_card": summary})


if __name__ == "__main__":
    app.run(port=5001, debug=False)


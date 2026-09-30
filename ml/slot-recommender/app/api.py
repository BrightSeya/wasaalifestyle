"""Flask service hosting the trained slot-recommender Random Forest model.

The model and completion-rate lookup are loaded once at startup. If either file is
missing (e.g. before training has been run), the app still starts so /health can report
the real load state instead of crashing.
"""

import sys
from pathlib import Path

import joblib
import pandas as pd
from flask import Flask, jsonify, request

ROOT = Path(__file__).resolve().parents[1]
MODEL_PATH = ROOT / "models" / "rf_model.joblib"
LOOKUP_PATH = ROOT / "data" / "processed" / "completion_rate_lookup.csv"

sys.path.append(str(ROOT / "src"))
from atus_category_map import CATEGORIES  # noqa: E402
from candidates import REQUEST_FIELDS, top_k_hours  # noqa: E402

TOP_K = 3
CONFIDENCE_THRESHOLD = 0.5

app = Flask(__name__)

model = None
lookup = None

try:
    model = joblib.load(MODEL_PATH)
    lookup = pd.read_csv(LOOKUP_PATH)
except FileNotFoundError:
    pass


@app.get("/health")
def health():
    return jsonify({"status": "ok", "model_loaded": model is not None})


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
    if model is None or lookup is None:
        return jsonify({"error": "Model not loaded"}), 503

    data = request.get_json(silent=True)
    error = validate_request(data)
    if error:
        return jsonify({"error": error}), 400

    request_body = {field: data[field] for field in REQUEST_FIELDS}
    score_fn = lambda df: model.predict_proba(df)[:, 1]  # noqa: E731
    top = top_k_hours(request_body, lookup, score_fn, k=TOP_K)

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


if __name__ == "__main__":
    app.run(port=5001, debug=False)


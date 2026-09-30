"""Flask service hosting the trained slot-recommender Random Forest model.

The model and completion-rate lookup are loaded once at startup. If either file is
missing (e.g. before training has been run), the app still starts so /health can report
the real load state instead of crashing.
"""

from pathlib import Path

import joblib
import pandas as pd
from flask import Flask, jsonify

ROOT = Path(__file__).resolve().parents[1]
MODEL_PATH = ROOT / "models" / "rf_model.joblib"
LOOKUP_PATH = ROOT / "data" / "processed" / "completion_rate_lookup.csv"

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


if __name__ == "__main__":
    app.run(port=5001, debug=False)

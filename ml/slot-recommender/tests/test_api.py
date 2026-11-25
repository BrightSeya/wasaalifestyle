"""Integration tests for the Flask API (#48, #49), using Flask's test client (no live server).

These tests need a real trained model (python src/train.py) and completion-rate lookup
(python src/features.py or src/negatives.py). Both are git-ignored local artefacts, so the
whole module skips rather than fails when they are missing, e.g. on a fresh checkout in CI.
"""

import pytest

import api
from api import app

pytestmark = pytest.mark.skipif(
    api.model is None,
    reason="rf_model.joblib not found; run `python src/train.py` first",
)


@pytest.fixture
def client():
    app.testing = True
    return app.test_client()


def valid_body(**overrides):
    body = {
        "category": "exercise",
        "day_of_week": 1,
        "duration_min": 45,
        "schedule_density": 3,
        "gap_since_last_same_category_hrs": 24,
    }
    body.update(overrides)
    return body


def test_health_reports_the_model_as_loaded(client):
    response = client.get("/health")

    assert response.status_code == 200
    assert response.get_json() == {"status": "ok", "model_loaded": True}


def test_predict_returns_three_ranked_recommendations(client):
    response = client.post("/predict", json=valid_body())

    assert response.status_code == 200
    data = response.get_json()
    assert data["category"] == "exercise"
    assert data["day_of_week"] == 1
    assert len(data["recommendations"]) == 3

    hours = [r["hour"] for r in data["recommendations"]]
    confidences = [r["confidence"] for r in data["recommendations"]]
    assert all(0 <= h <= 23 for h in hours)
    assert confidences == sorted(confidences, reverse=True)
    for rec in data["recommendations"]:
        assert rec["suitable"] == (rec["confidence"] >= api.suitability_threshold)


def test_predict_uses_a_qualified_personal_model(client, monkeypatch):
    history = [{
        "category": "exercise",
        "day_of_week": 1,
        "hour": 9,
        "duration_min": 45,
        "schedule_density": 1,
        "gap_since_last_same_category_hrs": 24.0,
        "label": 1,
        "weight": 0.9,
        "occurred_at": 1_700_000_000,
    }]
    captured = {}

    class PersonalModel:
        def predict_proba(self, features):
            import numpy as np

            positive = np.full(len(features), 0.3)
            return np.column_stack((1 - positive, positive))

    def fit_personal(history_arg, lookup, population_model):
        captured["history"] = history_arg
        return PersonalModel(), {"status": "active", "decision_threshold": 0.2}

    monkeypatch.setattr(api, "fit_personal_model", fit_personal)
    monkeypatch.setattr(api, "suitability_threshold", 0.9)

    response = client.post("/predict", json=valid_body(personal_history=history))

    assert response.status_code == 200
    data = response.get_json()
    assert data["prediction_basis"] == "per_user_model"
    assert data["personal_model_status"] == "active"
    assert all(recommendation["suitable"] for recommendation in data["recommendations"])
    assert captured["history"] == history


def test_predict_returns_503_when_the_model_is_not_loaded(client, monkeypatch):
    monkeypatch.setattr(api, "model", None)

    response = client.post("/predict", json=valid_body())

    assert response.status_code == 503


def test_predict_rejects_missing_fields(client):
    response = client.post("/predict", json={"category": "exercise", "day_of_week": 1})

    assert response.status_code == 400
    assert "duration_min" in response.get_json()["error"]


def test_predict_rejects_invalid_category(client):
    response = client.post("/predict", json=valid_body(category="gaming"))

    assert response.status_code == 400
    assert "category must be one of" in response.get_json()["error"]


def test_predict_rejects_day_of_week_out_of_range(client):
    response = client.post("/predict", json=valid_body(day_of_week=7))

    assert response.status_code == 400
    assert "day_of_week" in response.get_json()["error"]


def test_predict_rejects_non_numeric_duration(client):
    response = client.post("/predict", json=valid_body(duration_min="a while"))

    assert response.status_code == 400
    assert "duration_min" in response.get_json()["error"]


def test_predict_rejects_zero_duration(client):
    response = client.post("/predict", json=valid_body(duration_min=0))

    assert response.status_code == 400


def test_predict_rejects_negative_schedule_density(client):
    response = client.post("/predict", json=valid_body(schedule_density=-1))

    assert response.status_code == 400


def test_predict_rejects_a_non_json_body(client):
    response = client.post("/predict", data="not json", content_type="text/plain")

    assert response.status_code == 400

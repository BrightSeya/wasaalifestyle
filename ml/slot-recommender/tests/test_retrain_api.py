"""Tests for the authenticated model retraining endpoint."""

import json

import joblib
import pandas as pd
import pytest

import api


@pytest.fixture
def client():
    original_model, original_lookup = api.model, api.lookup
    api.app.testing = True
    yield api.app.test_client()
    api.model, api.lookup = original_model, original_lookup


def test_retrain_requires_a_configured_key(client, monkeypatch):
    monkeypatch.delenv("ML_RETRAIN_KEY", raising=False)

    response = client.post("/retrain", headers={"X-API-Key": "anything"})

    assert response.status_code == 401
    assert response.get_json() == {"error": "Unauthorized"}


def test_retrain_rejects_a_missing_key(client, monkeypatch):
    monkeypatch.setenv("ML_RETRAIN_KEY", "correct-secret")

    response = client.post("/retrain")

    assert response.status_code == 401


def test_retrain_rejects_a_wrong_key(client, monkeypatch):
    monkeypatch.setenv("ML_RETRAIN_KEY", "correct-secret")

    response = client.post("/retrain", headers={"X-API-Key": "wrong-secret"})

    assert response.status_code == 401


def test_retrain_runs_training_reloads_artifacts_and_returns_model_card(tmp_path, client, monkeypatch):
    monkeypatch.setenv("ML_RETRAIN_KEY", "correct-secret")
    model_path = tmp_path / "rf_model.joblib"
    lookup_path = tmp_path / "completion_rate_lookup.csv"
    model_card_path = tmp_path / "model_card.json"
    monkeypatch.setattr(api, "MODEL_PATH", model_path)
    monkeypatch.setattr(api, "LOOKUP_PATH", lookup_path)
    monkeypatch.setattr(api, "MODEL_CARD_PATH", model_card_path)
    previous_model = {"version": "previous"}
    api.model = previous_model
    api.lookup = pd.DataFrame({"version": ["previous"]})
    trained = []

    def fake_train():
        trained.append(True)
        joblib.dump({"version": "new"}, model_path)
        pd.DataFrame({"version": ["new"]}).to_csv(lookup_path, index=False)
        model_card_path.write_text(json.dumps({
            "cv_f1": 0.73,
            "best_params": {"n_estimators": 300},
        }), encoding="utf-8")

    monkeypatch.setattr(api.train, "main", fake_train)

    response = client.post("/retrain", headers={"X-API-Key": "correct-secret"})

    assert response.status_code == 200
    assert response.get_json() == {
        "status": "ok",
        "model_card": {"cv_f1": 0.73, "best_params": {"n_estimators": 300}},
    }
    assert trained == [True]
    assert api.model == {"version": "new"}
    assert api.lookup.iloc[0]["version"] == "new"


def test_retrain_failure_returns_clear_error_and_keeps_loaded_model(client, monkeypatch):
    monkeypatch.setenv("ML_RETRAIN_KEY", "correct-secret")
    previous_model = {"version": "previous"}
    previous_lookup = pd.DataFrame({"version": ["previous"]})
    api.model = previous_model
    api.lookup = previous_lookup

    def fail_training():
        raise RuntimeError("training data unavailable")

    monkeypatch.setattr(api.train, "main", fail_training)

    response = client.post("/retrain", headers={"X-API-Key": "correct-secret"})

    assert response.status_code == 503
    assert response.get_json() == {
        "error": "Retraining failed; the previously loaded model remains active.",
    }
    assert api.model is previous_model
    assert api.lookup is previous_lookup


def test_retrain_artifact_reload_failure_keeps_loaded_model(tmp_path, client, monkeypatch):
    monkeypatch.setenv("ML_RETRAIN_KEY", "correct-secret")
    monkeypatch.setattr(api, "MODEL_PATH", tmp_path / "missing-model.joblib")
    previous_model = {"version": "previous"}
    previous_lookup = pd.DataFrame({"version": ["previous"]})
    api.model = previous_model
    api.lookup = previous_lookup
    monkeypatch.setattr(api.train, "main", lambda: None)

    response = client.post("/retrain", headers={"X-API-Key": "correct-secret"})

    assert response.status_code == 503
    assert api.model is previous_model
    assert api.lookup is previous_lookup

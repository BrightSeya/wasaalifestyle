import api
import upstream_cold_start
import pytest


@pytest.fixture
def client():
    api.app.testing = True
    return api.app.test_client()


@pytest.fixture
def enabled_model(monkeypatch):
    monkeypatch.setattr(api, "risk_model_enabled", True)
    monkeypatch.setattr(api, "risk_model_bundle", {"test_bundle": True})
    monkeypatch.setattr(
        api,
        "risk_model_card",
        {"model": "test model", "git_commit": "synthetic-test"},
    )
    monkeypatch.setattr(api, "predict_probability", lambda _bundle, _rows: [0.73125])
    monkeypatch.setattr(
        api,
        "explain_factors",
        lambda _bundle, _row, limit=3: [{"feature": "prior_missed_rate", "value": 0.5, "log_odds_contribution": 0.42}][:limit],
    )


def valid_body(**overrides):
    body = {
        "category": "work",
        "priority": "medium",
        "weekday": 2,
        "scheduled_hour": 14,
        "duration_minutes": 60,
        "lead_hours": 24.0,
        "prior_labeled_count": 0,
        "prior_missed_rate": None,
        "prior_same_category_labeled_count": 0,
        "prior_same_category_missed_rate": None,
    }
    body.update(overrides)
    return body


def test_commitment_risk_is_unavailable_when_operator_disables_the_model(client, monkeypatch):
    monkeypatch.setattr(api, "risk_model_enabled", False)
    monkeypatch.setattr(api, "risk_model_bundle", None)
    monkeypatch.setattr(api, "risk_model_card", None)

    response = client.post("/noshow/predict", json=valid_body())

    assert response.status_code == 503
    assert response.get_json() == {"error": "Commitment-risk prediction is unavailable."}


def test_commitment_risk_is_unavailable_when_enabled_without_artifact(client, monkeypatch):
    monkeypatch.setattr(api, "risk_model_enabled", True)
    monkeypatch.setattr(api, "risk_model_bundle", None)
    monkeypatch.setattr(api, "risk_model_card", None)

    response = client.post("/noshow/predict", json=valid_body())

    assert response.status_code == 503


def test_upstream_synthetic_artifacts_need_explicit_prototype_allowance():
    card = {
        "model": "upstream model",
        "evidence_type": "synthetic-upstream",
        "model_version": "upstream-synthetic-abc123",
    }
    bundle = {
        "model_type": "upstream-synthetic-cold-start-v1",
        "features": api.RISK_FEATURES,
        "version": "upstream-synthetic-abc123",
    }

    assert not api.risk_artifact_supported(card, bundle, allow_synthetic=False)
    assert api.risk_artifact_supported(card, bundle, allow_synthetic=True)


def test_synthetic_model_bundle_cannot_mismatch_its_card():
    card = {
        "model": "upstream model",
        "evidence_type": "synthetic-upstream",
        "model_version": "upstream-synthetic-abc123",
    }
    bundle = {
        "model_type": "upstream-synthetic-cold-start-v1",
        "features": api.RISK_FEATURES,
        "version": "other-version",
    }

    assert not api.risk_artifact_supported(card, bundle, allow_synthetic=True)


def test_upstream_cold_start_model_produces_labeled_prototype_estimate(client, monkeypatch):
    bundle, card = upstream_cold_start.train_upstream_cold_start()
    monkeypatch.setattr(api, "risk_model_enabled", True)
    monkeypatch.setattr(api, "risk_model_bundle", bundle)
    monkeypatch.setattr(api, "risk_model_card", card)

    response = client.post("/noshow/predict", json=valid_body())

    assert response.status_code == 200
    body = response.get_json()
    assert 0 <= body["probability"] <= 1
    assert body["model"]["evidence_type"] == "synthetic-upstream"
    assert body["model"]["prototype_only"] is True
    assert body["model"]["version"] == "upstream-synthetic-c40599197b05"
    assert card["user_facing_predictions_enabled"] is True


def test_prediction_returns_probability_factors_and_version_without_identifiers(client, enabled_model):
    response = client.post("/noshow/predict", json=valid_body())

    assert response.status_code == 200
    body = response.get_json()
    assert body["probability"] == 0.7312
    assert body["factors"][0]["feature"] == "prior_missed_rate"
    assert body["model"] == {
        "name": "test model",
        "version": "synthetic-test",
        "evidence_type": None,
        "prototype_only": False,
    }
    assert "user_id" not in body
    assert "schedule_id" not in body


@pytest.mark.parametrize(
    "overrides",
    [
        {"weekday": 7},
        {"scheduled_hour": -1},
        {"duration_minutes": 0},
        {"lead_hours": float("inf")},
        {"prior_labeled_count": -1},
        {"prior_missed_rate": 1.2, "prior_labeled_count": 2},
        {"prior_labeled_count": 2, "prior_missed_rate": None},
        {"category": []},
        {"priority": "urgent"},
    ],
)
def test_prediction_rejects_malformed_and_out_of_range_fields(client, enabled_model, overrides):
    response = client.post("/noshow/predict", json=valid_body(**overrides))

    assert response.status_code == 400


def test_prediction_rejects_missing_fields(client, enabled_model):
    body = valid_body()
    del body["lead_hours"]

    response = client.post("/noshow/predict", json=body)

    assert response.status_code == 400


def test_prediction_rejects_unexpected_identifiers_without_echoing_values(client, enabled_model):
    response = client.post("/noshow/predict", json=valid_body(user_id="private-user-123", schedule_id=987))

    assert response.status_code == 400
    assert "private-user-123" not in response.get_data(as_text=True)
    assert "987" not in response.get_data(as_text=True)


def test_prediction_rejects_a_non_json_body(client, enabled_model):
    response = client.post("/noshow/predict", data="not json", content_type="text/plain")

    assert response.status_code == 400


def test_prediction_rejects_non_object_json(client, enabled_model):
    response = client.post("/noshow/predict", json=[1, 2, 3])

    assert response.status_code == 400


def test_inference_errors_do_not_echo_sensitive_details(client, enabled_model, monkeypatch, caplog):
    def fail_with_sensitive_detail(_bundle, _rows):
        raise RuntimeError("private-user-123 schedule text")

    monkeypatch.setattr(api, "predict_probability", fail_with_sensitive_detail)

    response = client.post("/noshow/predict", json=valid_body())

    assert response.status_code == 503
    assert "private-user-123" not in response.get_data(as_text=True)
    assert "schedule text" not in caplog.text

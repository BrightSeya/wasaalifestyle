import api


def test_slot_service_has_no_no_show_model_endpoint():
    response = api.app.test_client().post("/noshow/predict", json={})

    assert response.status_code == 404

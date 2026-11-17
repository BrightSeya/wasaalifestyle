import pytest

from api import validate_user_feedback


def valid_bin(**overrides):
    counts = {
        "category_completed": 2.0,
        "category_total": 3.0,
        "other_completed": 1.0,
        "other_total": 2.0,
    }
    counts.update(overrides)
    return {"2:15": counts}


def test_user_feedback_accepts_empty_and_valid_counts():
    assert validate_user_feedback({}) is None
    assert validate_user_feedback(valid_bin()) is None


@pytest.mark.parametrize("feedback", [
    {"7:15": valid_bin()["2:15"]},
    {"2:24": valid_bin()["2:15"]},
    {"2:15": {**valid_bin()["2:15"], "category_total": -1}},
    {"2:15": {**valid_bin()["2:15"], "category_completed": 4}},
    {"2:15": {**valid_bin()["2:15"], "other_total": float("nan")}},
    {"2:15": {**valid_bin()["2:15"], "user_id": 123}},
])
def test_user_feedback_rejects_invalid_counts(feedback):
    assert validate_user_feedback(feedback) is not None

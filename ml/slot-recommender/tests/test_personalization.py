import pytest

from api import validate_personal_history, validate_user_feedback


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


def valid_history_example(**overrides):
    example = {
        "category": "exercise",
        "day_of_week": 2,
        "hour": 15,
        "duration_min": 45,
        "schedule_density": 2,
        "gap_since_last_same_category_hrs": 24.0,
        "label": 1,
        "weight": 0.8,
        "occurred_at": 1_700_000_000,
    }
    example.update(overrides)
    return example


def test_personal_history_accepts_empty_and_valid_examples():
    assert validate_personal_history([]) is None
    assert validate_personal_history([valid_history_example()]) is None


@pytest.mark.parametrize("example", [
    valid_history_example(category="gaming"),
    valid_history_example(day_of_week=7),
    valid_history_example(hour=True),
    valid_history_example(duration_min=0),
    valid_history_example(schedule_density=-1),
    valid_history_example(gap_since_last_same_category_hrs=float("inf")),
    valid_history_example(label=2),
    valid_history_example(weight=0),
    {**valid_history_example(), "user_id": 123},
])
def test_personal_history_rejects_invalid_examples(example):
    assert validate_personal_history([example]) is not None


def test_personal_history_rejects_too_many_examples():
    assert validate_personal_history([valid_history_example()] * 2001) is not None

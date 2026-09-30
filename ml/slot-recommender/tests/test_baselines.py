import pandas as pd
import pytest

from baselines import completion_rate_score, evaluate_binary, evaluate_ranking
from features import fit_completion_rate


@pytest.fixture
def lookup():
    # Unique peaks so top-1 is deterministic: work peaks at hour 9, exercise at hour 15.
    train = pd.DataFrame({
        "category": ["work", "work", "work", "exercise", "exercise", "exercise"],
        "hour": [9, 9, 9, 14, 15, 15],
    })
    return fit_completion_rate(train)


def test_completion_rate_score_returns_the_column(lookup):
    df = pd.DataFrame({"historical_completion_rate": [0.2, 0.9]})

    assert list(completion_rate_score(df)) == [0.2, 0.9]


def test_evaluate_binary_reports_precision_recall_f1():
    result = evaluate_binary([1, 1, 0], [0.9, 0.4, 0.1])

    assert set(result.keys()) == {"precision", "recall", "f1"}
    assert result["precision"] == pytest.approx(1.0)
    assert result["recall"] == pytest.approx(0.5)


def test_evaluate_ranking_hits_the_true_hour_when_it_is_the_categorys_peak(lookup):
    positives = pd.DataFrame({
        "category": ["work", "exercise"],
        "hour": [9, 15],
        "day_of_week": [0, 0],
        "duration_min": [60, 30],
        "schedule_density": [2, 1],
        "gap_since_last_same_category_hrs": [24.0, 24.0],
    })

    result = evaluate_ranking(positives, lookup, completion_rate_score)

    assert result["hit_rate_at_3"] == 1.0
    assert result["mae_hours"] == 0.0


def test_evaluate_ranking_reports_the_miss_distance(lookup):
    # This activity's real hour (2am) is nowhere near work's peak (9am, the unique top score).
    positives = pd.DataFrame({
        "category": ["work"],
        "hour": [2],
        "day_of_week": [0],
        "duration_min": [60],
        "schedule_density": [0],
        "gap_since_last_same_category_hrs": [24.0],
    })

    result = evaluate_ranking(positives, lookup, completion_rate_score)

    assert result["mae_hours"] == 7.0  # |9 - 2|

def test_evaluate_ranking_with_no_positives_is_zero(lookup):
    empty = pd.DataFrame(columns=["category", "hour", "day_of_week", "duration_min", "schedule_density", "gap_since_last_same_category_hrs"])

    assert evaluate_ranking(empty, lookup, completion_rate_score) == {"hit_rate_at_3": 0.0, "mae_hours": 0.0}


def test_evaluate_ranking_keeps_each_activitys_own_true_hour(lookup):
    # Two activities of different categories; if the batch builder ever paired an
    # activity's true hour with a different activity's candidate ranking, at least one
    # of these would incorrectly miss instead of hitting its own category's unique peak.
    positives = pd.DataFrame({
        "category": ["work", "exercise"],
        "hour": [9, 15],
        "day_of_week": [0, 1],
        "duration_min": [60, 30],
        "schedule_density": [2, 0],
        "gap_since_last_same_category_hrs": [24.0, 5.0],
    })

    result = evaluate_ranking(positives, lookup, completion_rate_score, k=1)

    assert result["hit_rate_at_3"] == 1.0

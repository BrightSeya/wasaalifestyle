import pytest

from metrics import circular_hour_distance, hit_rate_at_k, mean_absolute_error_hours, precision_recall_f1


def test_precision_recall_f1_on_a_known_example():
    y_true = [1, 1, 0, 0, 1]
    y_score = [0.9, 0.4, 0.3, 0.8, 0.6]  # threshold 0.5 -> predicted [1, 0, 0, 1, 1]

    result = precision_recall_f1(y_true, y_score, threshold=0.5)

    assert result["precision"] == pytest.approx(2 / 3)  # 2 correct out of 3 predicted positive
    assert result["recall"] == pytest.approx(2 / 3)  # 2 correct out of 3 actual positive
    assert result["f1"] == pytest.approx(2 / 3)


def test_precision_recall_f1_handles_no_positive_predictions():
    result = precision_recall_f1([1, 0], [0.1, 0.2], threshold=0.5)

    assert result == {"precision": 0.0, "recall": 0.0, "f1": 0.0}


@pytest.mark.parametrize(
    ("a", "b", "expected"),
    [
        (10, 10, 0),
        (10, 13, 3),
        (23, 1, 2),
        (0, 23, 1),
        (0, 12, 12),
    ],
)
def test_circular_hour_distance(a, b, expected):
    assert circular_hour_distance(a, b) == expected
    assert circular_hour_distance(b, a) == expected  # symmetric


def test_hit_rate_at_k_counts_activities_whose_true_hour_is_in_the_top_k():
    true_hours = [9, 14, 20]
    ranked = [
        [9, 10, 11],  # hit: 9 is top-1
        [8, 9, 14],  # hit: 14 is 3rd
        [1, 2, 3],  # miss
    ]

    assert hit_rate_at_k(true_hours, ranked, k=3) == pytest.approx(2 / 3)


def test_hit_rate_at_k_only_looks_at_the_first_k(): 
    assert hit_rate_at_k([5], [[1, 2, 5]], k=2) == 0.0
    assert hit_rate_at_k([5], [[1, 2, 5]], k=3) == 1.0


def test_hit_rate_at_k_with_no_activities_is_zero():
    assert hit_rate_at_k([], [], k=3) == 0.0


def test_mean_absolute_error_hours():
    true_hours = [9, 23, 0]
    top_hours = [9, 1, 12]  # distances: 0, 2, 12

    assert mean_absolute_error_hours(true_hours, top_hours) == pytest.approx(14 / 3)


def test_mean_absolute_error_hours_with_no_activities_is_zero():
    assert mean_absolute_error_hours([], []) == 0.0

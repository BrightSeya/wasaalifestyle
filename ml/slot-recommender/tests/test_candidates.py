import pandas as pd
import pytest

from atus_category_map import CATEGORIES
from candidates import REQUEST_FIELDS, build_candidates, rank_hours, score_candidates, top_k_hours
from features import fit_completion_rate


@pytest.fixture
def lookup():
    train = pd.DataFrame({
        "category": ["work"] * 3 + ["exercise"],
        "hour": [9, 9, 14, 7],
    })
    return fit_completion_rate(train)


@pytest.fixture
def request_body():
    return {
        "category": "work",
        "day_of_week": 2,
        "duration_min": 60,
        "schedule_density": 3,
        "gap_since_last_same_category_hrs": 24.0,
    }


def test_build_candidates_has_one_row_per_hour(request_body, lookup):
    candidates = build_candidates(request_body, lookup)

    assert list(candidates["hour"]) == list(range(24))
    assert (candidates["duration_min"] == 60).all()
    assert (candidates["schedule_density"] == 3).all()


def test_build_candidates_attaches_the_completion_rate_per_hour(request_body, lookup):
    candidates = build_candidates(request_body, lookup).set_index("hour")

    assert candidates.loc[9, "historical_completion_rate"] == 1.0
    assert candidates.loc[14, "historical_completion_rate"] == pytest.approx(0.5)
    assert candidates.loc[3, "historical_completion_rate"] == 0.0


def test_build_candidates_one_hot_encodes_the_requested_category(request_body, lookup):
    candidates = build_candidates(request_body, lookup)

    assert (candidates["category_work"] == 1).all()
    for category in CATEGORIES:
        if category != "work":
            assert (candidates[f"category_{category}"] == 0).all()


def test_missing_request_field_is_rejected(lookup):
    incomplete = {field: 0 for field in REQUEST_FIELDS[:-1]}

    with pytest.raises(ValueError):
        build_candidates(incomplete, lookup)


def test_score_candidates_ranks_best_first(request_body, lookup):
    # A deliberately simple scorer: prefer later hours.
    ranked = score_candidates(request_body, lookup, score_fn=lambda df: df["hour"])

    assert ranked.iloc[0]["hour"] == 23
    assert ranked.iloc[-1]["hour"] == 0
    assert list(ranked["score"]) == sorted(ranked["score"], reverse=True)


def test_rank_hours_returns_all_24_in_order(request_body, lookup):
    ranked = rank_hours(request_body, lookup, score_fn=lambda df: -df["hour"])

    assert ranked == list(range(24))


def test_top_k_hours_matches_the_start_of_rank_hours(request_body, lookup):
    score_fn = lambda df: df["historical_completion_rate"]  # noqa: E731

    top = top_k_hours(request_body, lookup, score_fn, k=3)
    full_ranking = rank_hours(request_body, lookup, score_fn)

    assert len(top) == 3
    assert [row["hour"] for row in top] == full_ranking[:3]
    assert top[0]["hour"] == 9  # work's only peak hour in this fixture

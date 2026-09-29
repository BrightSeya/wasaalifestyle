import pandas as pd
import pytest

from atus_category_map import CATEGORIES
from features import (
    CATEGORY_COLUMNS,
    NO_PREVIOUS_GAP_HRS,
    add_context_features,
    apply_completion_rate,
    fit_completion_rate,
    one_hot_category,
)


@pytest.fixture
def day():
    # One respondent's diary: rows deliberately out of order; 01:00 is after midnight (diary_min 1260).
    return pd.DataFrame(
        {
            "tucaseid": ["A"] * 5,
            "category": ["work", "work", "exercise", "leisure", "work"],
            "hour": [13, 8, 18, 1, 20],
            "diary_min": [540, 240, 840, 1260, 960],
        }
    )


def test_schedule_density_counts_other_activities(day):
    other = pd.DataFrame({"tucaseid": ["B"], "category": ["rest"], "hour": [22], "diary_min": [1080]})
    out = add_context_features(pd.concat([day, other]))

    assert set(out.loc[out["tucaseid"] == "A", "schedule_density"]) == {4}
    assert set(out.loc[out["tucaseid"] == "B", "schedule_density"]) == {0}


def test_gap_uses_previous_activity_of_the_same_category(day):
    out = add_context_features(day).set_index("hour")

    assert out.loc[8, "gap_since_last_same_category_hrs"] == NO_PREVIOUS_GAP_HRS
    assert out.loc[13, "gap_since_last_same_category_hrs"] == 5.0  # 08:00 -> 13:00
    assert out.loc[20, "gap_since_last_same_category_hrs"] == 7.0  # 13:00 -> 20:00
    assert out.loc[18, "gap_since_last_same_category_hrs"] == NO_PREVIOUS_GAP_HRS


def test_rows_are_ordered_on_the_diary_clock(day):
    out = add_context_features(day)
    assert list(out["hour"]) == [8, 13, 18, 20, 1]


@pytest.fixture
def train():
    return pd.DataFrame({"category": ["work"] * 4 + ["exercise"] * 2, "hour": [9, 9, 9, 14, 7, 18]})


def test_lookup_covers_every_category_and_hour(train):
    lookup = fit_completion_rate(train)
    assert len(lookup) == len(CATEGORIES) * 24


def test_lookup_is_scaled_within_each_category(train):
    rate = fit_completion_rate(train).set_index(["category", "hour"])["historical_completion_rate"]

    assert rate[("work", 9)] == 1.0
    assert rate[("work", 14)] == pytest.approx(1 / 3, abs=1e-4)
    # Exercise is a small category but its busiest hours still score 1.
    assert rate[("exercise", 7)] == 1.0
    assert rate[("work", 3)] == 0.0
    assert rate[("social", 12)] == 0.0


def test_applying_the_lookup_does_not_learn_from_new_data(train):
    lookup = fit_completion_rate(train)
    test = pd.DataFrame({"category": ["work", "work", "rest"], "hour": [9, 14, 3]})

    out = apply_completion_rate(test, lookup)

    assert list(out["historical_completion_rate"].round(4)) == [1.0, 0.3333, 0.0]
    assert fit_completion_rate(train).equals(lookup)


def test_applying_replaces_an_existing_rate(train):
    lookup = fit_completion_rate(train)
    df = pd.DataFrame({"category": ["work"], "hour": [9], "historical_completion_rate": [0.1]})

    assert apply_completion_rate(df, lookup)["historical_completion_rate"].iloc[0] == 1.0


def test_one_hot_has_all_categories_in_order():
    out = one_hot_category(pd.DataFrame({"category": ["exercise", "rest"]}))

    assert [c for c in out.columns if c.startswith("category_")] == CATEGORY_COLUMNS
    assert out[CATEGORY_COLUMNS].sum(axis=1).tolist() == [1, 1]
    assert out["category_exercise"].tolist() == [1, 0]
    assert out["category_work"].tolist() == [0, 0]

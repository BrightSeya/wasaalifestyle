import pandas as pd
import pytest

from features import FEATURE_COLUMNS, fit_completion_rate
from negatives import OUTPUT_COLUMNS, build_dataset, generate_negatives


@pytest.fixture
def positives():
    # A: work at 8 and 13, exercise at 18, leisure at 20. B: a single activity, so no clash is possible.
    return pd.DataFrame(
        {
            "tucaseid": ["A", "A", "A", "A", "B"],
            "category": ["work", "work", "exercise", "leisure", "rest"],
            "hour": [8, 13, 18, 20, 22],
            "day_of_week": [0, 0, 0, 0, 6],
            "duration_min": [240, 240, 60, 120, 480],
            "diary_min": [240, 540, 840, 960, 1080],
            "schedule_density": [3, 3, 3, 3, 0],
            "gap_since_last_same_category_hrs": [24.0, 5.0, 24.0, 24.0, 24.0],
        }
    )


def test_negative_hour_clashes_with_another_category_only(positives):
    negatives = generate_negatives(positives)

    for _, neg in negatives.iterrows():
        person = positives[positives["tucaseid"] == neg["tucaseid"]]
        same_category_hours = set(person.loc[person["category"] == neg["category"], "hour"])
        other_category_hours = set(person.loc[person["category"] != neg["category"], "hour"])

        assert neg["hour"] in other_category_hours
        assert neg["hour"] not in same_category_hours


def test_person_with_no_possible_clash_gets_no_negative(positives):
    negatives = generate_negatives(positives)

    assert "B" not in set(negatives["tucaseid"])
    assert len(negatives) == 4


def test_negatives_keep_every_other_feature(positives):
    negatives = generate_negatives(positives)
    unchanged = ["tucaseid", "category", "day_of_week", "duration_min", "schedule_density"]

    merged = negatives.merge(positives, on="diary_min", suffixes=("_neg", "_pos"))
    for column in unchanged:
        assert (merged[f"{column}_neg"] == merged[f"{column}_pos"]).all()


def test_generation_is_reproducible(positives):
    assert generate_negatives(positives, seed=3).equals(generate_negatives(positives, seed=3))


def test_dataset_has_labels_and_model_columns(positives):
    data = build_dataset(positives, fit_completion_rate(positives))

    assert list(data.columns) == OUTPUT_COLUMNS
    assert data["label"].value_counts().to_dict() == {1: 5, 0: 4}
    assert data[FEATURE_COLUMNS].notna().all().all()


def test_completion_rate_follows_the_new_hour(positives):
    lookup = fit_completion_rate(positives)
    rate = lookup.set_index(["category", "hour"])["historical_completion_rate"]

    data = build_dataset(positives, lookup)

    for _, r in data.iterrows():
        assert r["historical_completion_rate"] == rate[(r["category"], r["hour"])]
    # Work never happened at 18 or 20 in training, so its negatives score 0.
    assert set(data.loc[(data["label"] == 0) & (data["category"] == "work"), "historical_completion_rate"]) == {0.0}

import pandas as pd
import pytest

from split import SPLITS, assign_splits, split_by_respondent


@pytest.fixture
def df():
    # 200 respondents with 1-5 activities each.
    rows = [
        {"tucaseid": f"2023{i:010d}", "tuactivity_n": n}
        for i in range(200)
        for n in range(1, i % 5 + 2)
    ]
    return pd.DataFrame(rows)


def test_respondent_proportions(df):
    counts = assign_splits(df).value_counts()
    assert counts["train"] == 140
    assert counts["val"] == 30
    assert counts["test"] == 30


def test_no_respondent_appears_in_two_sets(df):
    parts = split_by_respondent(df)
    ids = [set(parts[name]["tucaseid"]) for name in SPLITS]

    assert not ids[0] & ids[1]
    assert not ids[0] & ids[2]
    assert not ids[1] & ids[2]


def test_every_row_is_kept_exactly_once(df):
    parts = split_by_respondent(df)

    assert sum(len(parts[name]) for name in SPLITS) == len(df)
    combined = pd.concat(parts.values())
    assert not combined.duplicated(["tucaseid", "tuactivity_n"]).any()


def test_split_is_reproducible(df):
    assert assign_splits(df, seed=7).equals(assign_splits(df, seed=7))


def test_different_seed_gives_a_different_split(df):
    assert not assign_splits(df, seed=1).sort_index().equals(assign_splits(df, seed=2).sort_index())


def test_row_order_does_not_change_the_split(df):
    shuffled = df.sample(frac=1, random_state=0)
    assert assign_splits(df).sort_index().equals(assign_splits(shuffled).sort_index())


@pytest.mark.parametrize("fractions", [(0.7, 0.2, 0.2), (0.5, 0.5)])
def test_invalid_fractions_are_rejected(df, fractions):
    with pytest.raises(ValueError):
        assign_splits(df, fractions=fractions)

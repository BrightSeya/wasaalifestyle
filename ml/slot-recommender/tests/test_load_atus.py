from pathlib import Path

import pytest

from atus_category_map import CATEGORIES
from load_atus import OUTPUT_COLUMNS, clean, load_raw

FIXTURES = Path(__file__).parent / "fixtures"


@pytest.fixture
def raw():
    return load_raw(FIXTURES / "atusact_sample.dat", FIXTURES / "atusresp_sample.dat")


@pytest.fixture
def cleaned(raw):
    return clean(raw)


def row(df, caseid_suffix, activity_n):
    match = df[(df["tucaseid"] == f"2023010100000{caseid_suffix}") & (df["tuactivity_n"] == activity_n)]
    return match.iloc[0] if len(match) else None


def test_load_attaches_diary_day_and_keeps_ids_as_text(raw):
    assert len(raw) == 13
    assert raw["tudiaryday"].notna().all()
    assert raw["tucaseid"].map(type).eq(str).all()


def test_report_counts_every_drop(cleaned):
    _, report = cleaned
    assert report == {"raw_rows": 13, "unmapped": 1, "travel": 1, "diary_start": 2, "kept": 9}


def test_output_columns(cleaned):
    df, _ = cleaned
    assert list(df.columns) == OUTPUT_COLUMNS


def test_travel_and_unmapped_rows_are_removed(cleaned):
    df, _ = cleaned
    assert row(df, 1, 3) is None  # travel
    assert row(df, 2, 3) is None  # data code 50
    assert set(df["category"]) <= set(CATEGORIES)


def test_only_first_activity_at_exactly_0400_is_removed(cleaned):
    df, _ = cleaned
    assert row(df, 1, 1) is None
    assert row(df, 2, 1) is None
    assert row(df, 3, 1) is not None  # first activity but starts 04:30


def test_day_of_week_is_monday_zero(cleaned):
    df, _ = cleaned
    assert row(df, 1, 2)["day_of_week"] == 0  # ATUS 2 = Monday
    assert row(df, 2, 2)["day_of_week"] == 6  # ATUS 1 = Sunday
    assert row(df, 3, 1)["day_of_week"] == 5  # ATUS 7 = Saturday


def test_time_columns(cleaned):
    df, _ = cleaned
    work = row(df, 1, 4)
    assert (work["category"], work["hour"], work["duration_min"], work["diary_min"]) == ("work", 8, 480, 240)

    after_midnight = row(df, 2, 5)
    assert after_midnight["hour"] == 1
    assert after_midnight["diary_min"] == 21 * 60 + 30  # 01:30 is 21.5 hours after 04:00


def test_rows_are_ordered_within_each_diary(cleaned):
    df, _ = cleaned
    for _, group in df.groupby("tucaseid"):
        assert group["tuactivity_n"].is_monotonic_increasing

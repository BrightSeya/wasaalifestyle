"""CRISP-DM Data Preparation, step 1: load and clean the ATUS 2023 activity data.

Decisions applied (see docs/02_data_understanding.md):
- Activities that do not map to a Wasaa category are dropped.
- Travel (tier-1 18) is excluded: it links activities and is not something users schedule.
- Diary-start rows are excluded: the first activity of a diary day that "starts" at exactly
  04:00 only does so because the ATUS diary begins then (usually ongoing sleep).
"""

from pathlib import Path

import pandas as pd

from atus_category_map import map_activity_category

ROOT = Path(__file__).resolve().parents[1]
RAW_DIR = ROOT / "data" / "raw"
PROCESSED_DIR = ROOT / "data" / "processed"

TRAVEL_TIER1 = 18
DIARY_START = "04:00:00"
DIARY_START_MIN = 4 * 60

ACTIVITY_COLUMNS = ["TUCASEID", "TUACTIVITY_N", "TUSTARTTIM", "TUACTDUR24", "TUTIER1CODE", "TUTIER2CODE"]
RESPONDENT_COLUMNS = ["TUCASEID", "TUDIARYDAY"]
OUTPUT_COLUMNS = ["tucaseid", "tuactivity_n", "category", "hour", "day_of_week", "duration_min", "diary_min"]


def load_raw(act_path, resp_path):
    """Read the activity and respondent files and attach each activity's diary day."""
    act = pd.read_csv(act_path, usecols=ACTIVITY_COLUMNS, dtype={"TUCASEID": str})
    resp = pd.read_csv(resp_path, usecols=RESPONDENT_COLUMNS, dtype={"TUCASEID": str})

    df = act.merge(resp, on="TUCASEID", how="left", validate="many_to_one")
    df.columns = df.columns.str.lower()
    return df


def clean(df):
    """Apply the cleaning decisions and derive time columns. Returns (clean_df, report)."""
    report = {"raw_rows": len(df)}

    pairs = df[["tutier1code", "tutier2code"]].drop_duplicates()
    pairs["category"] = [map_activity_category(t1, t2) for t1, t2 in zip(pairs["tutier1code"], pairs["tutier2code"])]
    df = df.merge(pairs, on=["tutier1code", "tutier2code"], how="left")

    unmapped = df["category"].isna()
    report["unmapped"] = int(unmapped.sum())
    df = df[~unmapped]

    travel = df["tutier1code"] == TRAVEL_TIER1
    report["travel"] = int(travel.sum())
    df = df[~travel]

    diary_start = (df["tuactivity_n"] == 1) & (df["tustarttim"] == DIARY_START)
    report["diary_start"] = int(diary_start.sum())
    df = df[~diary_start].copy()

    start = pd.to_datetime(df["tustarttim"], format="%H:%M:%S")
    start_min = start.dt.hour * 60 + start.dt.minute

    df["hour"] = start.dt.hour
    # ATUS: 1 = Sunday ... 7 = Saturday. Wasaa and Python: 0 = Monday ... 6 = Sunday.
    df["day_of_week"] = (df["tudiaryday"] + 5) % 7
    df["duration_min"] = df["tuactdur24"].astype(int)
    # Minutes since the diary day began, so activities after midnight sort after the evening.
    df["diary_min"] = (start_min - DIARY_START_MIN) % (24 * 60)

    df = df[OUTPUT_COLUMNS].sort_values(["tucaseid", "tuactivity_n"]).reset_index(drop=True)
    report["kept"] = len(df)
    return df, report


def main():
    df, report = clean(load_raw(RAW_DIR / "atusact_2023.dat", RAW_DIR / "atusresp_2023.dat"))

    for step, rows in report.items():
        print(f"{step:>12}: {rows:,}")

    PROCESSED_DIR.mkdir(parents=True, exist_ok=True)
    out_path = PROCESSED_DIR / "atus_clean.csv"
    df.to_csv(out_path, index=False)
    print(f"Saved {len(df):,} rows -> {out_path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()

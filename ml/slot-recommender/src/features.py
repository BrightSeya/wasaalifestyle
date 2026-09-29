"""CRISP-DM Data Preparation, step 3: build the model features (proposal section 3.2.4).

- schedule_density: other activities the person has that day.
- gap_since_last_same_category_hrs: hours since the person last started an activity of the same
  category that day (24 if none), measured on the diary clock so after-midnight rows sort last.
- historical_completion_rate: how common the hour is for the category, learned from the
  training set only, then applied unchanged to validation and test.
- category one-hot columns in a fixed order.
"""

import pandas as pd

from atus_category_map import CATEGORIES
from load_atus import PROCESSED_DIR, ROOT
from split import SPLITS, split_by_respondent

NO_PREVIOUS_GAP_HRS = 24.0
CATEGORY_COLUMNS = [f"category_{c}" for c in CATEGORIES]
FEATURE_COLUMNS = [
    "hour",
    "day_of_week",
    "duration_min",
    "historical_completion_rate",
    "schedule_density",
    "gap_since_last_same_category_hrs",
    *CATEGORY_COLUMNS,
]


def add_context_features(df):
    """Add schedule_density and gap_since_last_same_category_hrs (computed within each respondent's day)."""
    df = df.sort_values(["tucaseid", "diary_min"]).copy()

    df["schedule_density"] = df.groupby("tucaseid")["tucaseid"].transform("size") - 1

    previous_start = df.groupby(["tucaseid", "category"])["diary_min"].shift()
    df["gap_since_last_same_category_hrs"] = ((df["diary_min"] - previous_start) / 60).fillna(NO_PREVIOUS_GAP_HRS).round(2)

    return df.reset_index(drop=True)


def fit_completion_rate(train_df):
    """Category x hour table: share of the category's activities at that hour, scaled so its busiest hour is 1."""
    counts = (
        train_df.groupby(["category", "hour"]).size()
        .reindex(pd.MultiIndex.from_product([CATEGORIES, range(24)], names=["category", "hour"]), fill_value=0)
        .rename("count")
        .reset_index()
    )
    peak = counts.groupby("category")["count"].transform("max")
    counts["historical_completion_rate"] = (counts["count"] / peak.where(peak > 0)).fillna(0.0).round(4)
    return counts[["category", "hour", "historical_completion_rate"]]


def apply_completion_rate(df, lookup):
    """Attach historical_completion_rate from a fitted lookup; unseen pairs get 0."""
    df = df.drop(columns="historical_completion_rate", errors="ignore")
    df = df.merge(lookup, on=["category", "hour"], how="left")
    df["historical_completion_rate"] = df["historical_completion_rate"].fillna(0.0)
    return df


def one_hot_category(df):
    """Add one column per category in CATEGORIES order, even if a category is absent from df."""
    df = df.copy()
    for category, column in zip(CATEGORIES, CATEGORY_COLUMNS):
        df[column] = (df["category"] == category).astype(int)
    return df


def main():
    clean = pd.read_csv(PROCESSED_DIR / "atus_clean.csv", dtype={"tucaseid": str})
    parts = {name: add_context_features(part) for name, part in split_by_respondent(clean).items()}

    lookup = fit_completion_rate(parts["train"])
    lookup.to_csv(PROCESSED_DIR / "completion_rate_lookup.csv", index=False)

    for name in SPLITS:
        features = one_hot_category(apply_completion_rate(parts[name], lookup))
        out_path = PROCESSED_DIR / f"{name}_positives.csv"
        features.to_csv(out_path, index=False)
        print(f"{name:>5}: {len(features):,} rows -> {out_path.relative_to(ROOT)}")

    print("\nPeak hour per category (train):")
    print(lookup.loc[lookup["historical_completion_rate"] == 1.0].set_index("category")["hour"].to_string())


if __name__ == "__main__":
    main()

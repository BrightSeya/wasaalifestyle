"""CRISP-DM Data Preparation, step 4: create "not suitable" examples and write the final datasets.

ATUS only records what people did, so every row is a positive (suitable) example. For each
activity, a negative copy is moved to another hour of the same person's day that was taken by a
different category and never by this one: a slot that clashes with the rest of their day.
Negatives are generated inside each split, so no information crosses between sets.
"""

import numpy as np
import pandas as pd

from features import (
    FEATURE_COLUMNS,
    add_context_features,
    apply_completion_rate,
    fit_completion_rate,
    one_hot_category,
)
from load_atus import PROCESSED_DIR, ROOT
from split import SEED, SPLITS, split_by_respondent

OUTPUT_COLUMNS = ["tucaseid", "category", *FEATURE_COLUMNS, "label"]


def generate_negatives(df, seed=SEED):
    """One negative per activity where possible; rows with no clashing hour are skipped."""
    rng = np.random.default_rng(seed)
    negatives = []

    for _, day in df.groupby("tucaseid", sort=True):
        hours_by_category = day.groupby("category")["hour"].agg(set).to_dict()
        all_hours = set(day["hour"])

        for record in day.to_dict("records"):
            options = sorted(all_hours - hours_by_category[record["category"]])
            if not options:
                continue
            negatives.append({**record, "hour": int(rng.choice(options))})

    return pd.DataFrame(negatives, columns=df.columns)


def build_dataset(positives, lookup, seed=SEED):
    """Positives (label 1) + negatives (label 0) with completion rate and one-hot applied."""
    negatives = generate_negatives(positives, seed)
    data = pd.concat([positives.assign(label=1), negatives.assign(label=0)], ignore_index=True)
    data = one_hot_category(apply_completion_rate(data, lookup))
    return data[OUTPUT_COLUMNS]


def main():
    clean = pd.read_csv(PROCESSED_DIR / "atus_clean.csv", dtype={"tucaseid": str})
    parts = {name: add_context_features(part) for name, part in split_by_respondent(clean).items()}

    lookup = fit_completion_rate(parts["train"])
    lookup.to_csv(PROCESSED_DIR / "completion_rate_lookup.csv", index=False)

    for offset, name in enumerate(SPLITS):
        data = build_dataset(parts[name], lookup, seed=SEED + offset)
        out_path = PROCESSED_DIR / f"{name}.csv"
        data.to_csv(out_path, index=False)

        positives = int(data["label"].sum())
        negatives = len(data) - positives
        print(f"{name:>5}: {len(data):,} rows ({positives:,} positive, {negatives:,} negative, "
              f"{positives / len(data):.1%} positive) -> {out_path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()

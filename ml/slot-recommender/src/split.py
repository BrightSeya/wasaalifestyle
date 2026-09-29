"""CRISP-DM Data Preparation, step 2: split respondents into train, validation and test.

Each respondent contributes ~18 activities. Splitting by row would put the same person in
both training and test, so the model would be tested on people it has already seen.
Splitting by respondent (TUCASEID) keeps every person in exactly one set.
"""

import numpy as np
import pandas as pd

from load_atus import PROCESSED_DIR, ROOT

SPLITS = ("train", "val", "test")
FRACTIONS = (0.70, 0.15, 0.15)
SEED = 42


def assign_splits(df, fractions=FRACTIONS, seed=SEED):
    """Return a Series mapping each respondent (tucaseid) to 'train', 'val' or 'test'."""
    if len(fractions) != 3 or not np.isclose(sum(fractions), 1.0):
        raise ValueError("fractions must be three values that sum to 1")

    ids = np.sort(df["tucaseid"].unique())
    shuffled = np.random.default_rng(seed).permutation(ids)

    n_train = round(len(ids) * fractions[0])
    n_val = round(len(ids) * fractions[1])
    labels = np.array(["train"] * n_train + ["val"] * n_val + ["test"] * (len(ids) - n_train - n_val))

    return pd.Series(labels, index=shuffled, name="split").rename_axis("tucaseid")


def split_by_respondent(df, fractions=FRACTIONS, seed=SEED):
    """Split the rows of df so that all rows of a respondent land in the same set."""
    assignment = assign_splits(df, fractions, seed)
    split_of_row = df["tucaseid"].map(assignment)
    return {name: df[split_of_row == name].reset_index(drop=True) for name in SPLITS}


def main():
    df = pd.read_csv(PROCESSED_DIR / "atus_clean.csv", dtype={"tucaseid": str})
    assignment = assign_splits(df)
    parts = split_by_respondent(df)

    for name in SPLITS:
        people = parts[name]["tucaseid"].nunique()
        print(f"{name:>5}: {people:,} respondents ({people / len(assignment):.1%}), {len(parts[name]):,} rows")

    out_path = PROCESSED_DIR / "split_assignment.csv"
    assignment.to_csv(out_path)
    print(f"Saved respondent assignment -> {out_path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()

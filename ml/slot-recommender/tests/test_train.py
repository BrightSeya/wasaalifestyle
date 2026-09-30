import numpy as np
import pandas as pd
import pytest
from sklearn.model_selection import GroupKFold

from features import CATEGORY_COLUMNS, FEATURE_COLUMNS
from train import build_model_card, fit

SMALL_GRID = {"n_estimators": [10], "max_depth": [3], "min_samples_leaf": [1]}


@pytest.fixture
def train_df():
    """A tiny synthetic training set: 12 respondents, 4 rows each, so tests run in milliseconds."""
    rng = np.random.default_rng(0)
    rows = []

    for group in range(12):
        for _ in range(4):
            row = {
                "tucaseid": f"group{group}",
                "hour": int(rng.integers(0, 24)),
                "day_of_week": int(rng.integers(0, 7)),
                "duration_min": int(rng.integers(15, 120)),
                "historical_completion_rate": float(rng.random()),
                "schedule_density": int(rng.integers(0, 5)),
                "gap_since_last_same_category_hrs": float(rng.integers(0, 24)),
                "label": int(rng.integers(0, 2)),
                **{column: 0 for column in CATEGORY_COLUMNS},
            }
            row[rng.choice(CATEGORY_COLUMNS)] = 1
            rows.append(row)

    return pd.DataFrame(rows)


def test_fit_returns_a_usable_classifier(train_df):
    search = fit(train_df, param_grid=SMALL_GRID, cv_folds=3, n_jobs=1)

    assert hasattr(search.best_estimator_, "predict_proba")
    assert search.best_params_ == {"n_estimators": 10, "max_depth": 3, "min_samples_leaf": 1}
    assert 0.0 <= search.best_score_ <= 1.0


def test_grouped_cv_never_splits_a_respondent_across_folds(train_df):
    cv = GroupKFold(n_splits=3)

    for train_idx, test_idx in cv.split(train_df[FEATURE_COLUMNS], train_df["label"], groups=train_df["tucaseid"]):
        train_groups = set(train_df.iloc[train_idx]["tucaseid"])
        test_groups = set(train_df.iloc[test_idx]["tucaseid"])
        assert not (train_groups & test_groups)


def test_model_card_has_the_expected_fields(train_df):
    search = fit(train_df, param_grid=SMALL_GRID, cv_folds=3, n_jobs=1)

    card = build_model_card(search, train_df, data_files=["train.csv"])

    assert card["best_params"] == {"n_estimators": 10, "max_depth": 3, "min_samples_leaf": 1}
    assert card["training_rows"] == len(train_df)
    assert card["feature_columns"] == FEATURE_COLUMNS
    assert set(card["feature_importances"].keys()) == set(FEATURE_COLUMNS)
    assert 0.0 <= card["cv_f1"] <= 1.0
    assert card["data_files"] == ["train.csv"]

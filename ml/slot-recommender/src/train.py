"""CRISP-DM Modelling: train the Random Forest classifier (proposal Section 3.2.4) and
evaluate it exactly like the baselines (#39), for a fair comparison.

Cross-validation is grouped by respondent (GroupKFold on tucaseid), not plain K-fold.
A respondent contributes ~18 rows; a plain K-fold could put some of a person's rows in
the training folds and others in the validation fold within the same search, leaking
information the same way the un-grouped train/val/test split did before #22.
"""

import json
import time

import joblib
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import GridSearchCV, GroupKFold

from baselines import evaluate_binary, evaluate_ranking
from features import FEATURE_COLUMNS
from load_atus import PROCESSED_DIR, ROOT

PARAM_GRID = {
    # Second CRISP-DM modelling iteration: the first grid (100/200/300, 10/20/None, 1/5)
    # picked n_estimators=300 (the top edge) and min_samples_leaf=5, both signalling
    # "search further this way" rather than "this is the peak". Re-centred here instead
    # of widened in every direction, to keep the search the same size (~18 combinations).
    "n_estimators": [300, 400, 500],
    "max_depth": [15, 20, 25],
    "min_samples_leaf": [2, 5],
}
CV_FOLDS = 5
RANDOM_STATE = 42


def fit(train, param_grid=PARAM_GRID, cv_folds=CV_FOLDS, n_jobs=-1):
    """Grid-search a Random Forest with GroupKFold on tucaseid, scored on F1."""
    X = train[FEATURE_COLUMNS]
    y = train["label"]
    groups = train["tucaseid"]

    search = GridSearchCV(
        RandomForestClassifier(random_state=RANDOM_STATE),
        param_grid,
        cv=GroupKFold(n_splits=cv_folds),
        scoring="f1",
        n_jobs=n_jobs,
    )
    search.fit(X, y, groups=groups)
    return search


def build_model_card(search, train, data_files):
    """Everything needed to explain and reproduce the deployed model."""
    importances = dict(zip(
        FEATURE_COLUMNS,
        search.best_estimator_.feature_importances_.round(4).tolist(),
    ))

    return {
        "best_params": search.best_params_,
        "cv_f1": round(float(search.best_score_), 4),
        "training_rows": len(train),
        "feature_columns": FEATURE_COLUMNS,
        "feature_importances": importances,
        "data_files": data_files,
    }


def main():
    train = pd.read_csv(PROCESSED_DIR / "train.csv", dtype={"tucaseid": str})
    val = pd.read_csv(PROCESSED_DIR / "val.csv")
    lookup = pd.read_csv(PROCESSED_DIR / "completion_rate_lookup.csv")
    val_positives = val[val["label"] == 1]

    print(f"Training on {len(train):,} rows with GroupKFold({CV_FOLDS}) grid search over {len(PARAM_GRID['n_estimators']) * len(PARAM_GRID['max_depth']) * len(PARAM_GRID['min_samples_leaf'])} combinations...")
    start = time.time()
    search = fit(train)
    elapsed = time.time() - start
    print(f"Done in {elapsed / 60:.1f} min. Best params: {search.best_params_}  CV F1: {search.best_score_:.3f}")

    models_dir = ROOT / "models"
    models_dir.mkdir(parents=True, exist_ok=True)
    joblib.dump(search.best_estimator_, models_dir / "rf_model.joblib")

    card = build_model_card(search, train, data_files=["train.csv", "val.csv", "completion_rate_lookup.csv"])
    (models_dir / "model_card.json").write_text(json.dumps(card, indent=2))

    score_fn = lambda df: search.best_estimator_.predict_proba(df)[:, 1]  # noqa: E731
    binary = evaluate_binary(val["label"], score_fn(val[FEATURE_COLUMNS]))
    ranking = evaluate_ranking(val_positives, lookup, score_fn)
    results = {**binary, **ranking}

    baselines_path = models_dir / "baselines.json"
    baselines = json.loads(baselines_path.read_text()) if baselines_path.exists() else {}
    baselines["random_forest"] = results
    baselines_path.write_text(json.dumps(baselines, indent=2))

    print(f"\nValidation: F1={results['f1']:.3f}  Hit Rate@3={results['hit_rate_at_3']:.3f}  MAE={results['mae_hours']:.2f}h")
    print("Saved -> models/rf_model.joblib, models/model_card.json, models/baselines.json")


if __name__ == "__main__":
    main()

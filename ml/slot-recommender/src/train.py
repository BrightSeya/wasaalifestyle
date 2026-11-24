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
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import GridSearchCV, GroupKFold

from baselines import completion_rate_score, evaluate_binary, evaluate_ranking
from features import FEATURE_COLUMNS
from load_atus import PROCESSED_DIR, ROOT
from metrics import best_f1_threshold

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


def build_model_card(search, train, data_files, decision_threshold=0.5, validation_metrics=None, test_metrics=None):
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
        "decision_threshold": float(decision_threshold),
        "validation_metrics": validation_metrics,
        "test_metrics": test_metrics,
    }


def main():
    train = pd.read_csv(PROCESSED_DIR / "train.csv", dtype={"tucaseid": str})
    val = pd.read_csv(PROCESSED_DIR / "val.csv")
    test = pd.read_csv(PROCESSED_DIR / "test.csv")
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

    score_fn = lambda df: search.best_estimator_.predict_proba(df)[:, 1]  # noqa: E731
    val_scores = score_fn(val[FEATURE_COLUMNS])
    decision_threshold = best_f1_threshold(val["label"], val_scores)
    binary = evaluate_binary(val["label"], val_scores, threshold=decision_threshold)
    ranking = evaluate_ranking(val_positives, lookup, score_fn)
    validation_results = {**binary, **ranking, "decision_threshold": decision_threshold}

    test_scores = score_fn(test[FEATURE_COLUMNS])
    test_binary = evaluate_binary(test["label"], test_scores, threshold=decision_threshold)
    test_ranking = evaluate_ranking(test[test["label"] == 1], lookup, score_fn)
    test_results = {**test_binary, **test_ranking, "decision_threshold": decision_threshold}

    card = build_model_card(
        search,
        train,
        data_files=["train.csv", "val.csv", "test.csv", "completion_rate_lookup.csv"],
        decision_threshold=decision_threshold,
        validation_metrics=validation_results,
        test_metrics=test_results,
    )
    (models_dir / "model_card.json").write_text(json.dumps(card, indent=2))

    logistic = LogisticRegression(max_iter=1000)
    logistic.fit(train[FEATURE_COLUMNS], train["label"])
    baseline_score_functions = {
        "completion_rate_only": completion_rate_score,
        "logistic_regression": lambda df: logistic.predict_proba(df)[:, 1],
    }
    baselines = {}
    for name, baseline_score_fn in baseline_score_functions.items():
        baseline_val_scores = baseline_score_fn(val[FEATURE_COLUMNS])
        baseline_threshold = best_f1_threshold(val["label"], baseline_val_scores)
        baseline_binary = evaluate_binary(val["label"], baseline_val_scores, threshold=baseline_threshold)
        baseline_ranking = evaluate_ranking(val_positives, lookup, baseline_score_fn)
        baselines[name] = {
            **baseline_binary,
            **baseline_ranking,
            "decision_threshold": baseline_threshold,
        }
    baselines["random_forest"] = validation_results

    baselines_path = models_dir / "baselines.json"
    baselines_path.write_text(json.dumps(baselines, indent=2))

    print(f"\nValidation (tuned threshold {decision_threshold:.4f}): F1={validation_results['f1']:.3f}  Hit Rate@3={validation_results['hit_rate_at_3']:.3f}  MAE={validation_results['mae_hours']:.2f}h")
    print(f"Held-out test: F1={test_results['f1']:.3f}  Hit Rate@3={test_results['hit_rate_at_3']:.3f}  MAE={test_results['mae_hours']:.2f}h")
    print("Saved -> models/rf_model.joblib, models/model_card.json, models/baselines.json")


if __name__ == "__main__":
    main()

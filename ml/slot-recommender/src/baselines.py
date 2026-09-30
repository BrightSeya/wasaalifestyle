"""CRISP-DM Modelling, step 0: baselines to beat before training the Random Forest (#40).

Baseline 1 needs no training: rank candidate hours by historical_completion_rate alone.
Baseline 2 is a simple trained model (logistic regression) on the same features.
Both are scored with the same metrics as the Random Forest will be (F1, Hit Rate@3, MAE),
so docs/04_modelling.md can compare them on equal terms.
"""

import json

import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression

from features import FEATURE_COLUMNS, apply_completion_rate, one_hot_category
from load_atus import PROCESSED_DIR, ROOT
from metrics import hit_rate_at_k, mean_absolute_error_hours, precision_recall_f1

TOP_K = 3
REQUEST_COLUMNS = ["category", "day_of_week", "duration_min", "schedule_density", "gap_since_last_same_category_hrs"]


def completion_rate_score(df):
    """Baseline 1: score = historical_completion_rate. No training required."""
    return df["historical_completion_rate"]


def evaluate_binary(y_true, y_score):
    return precision_recall_f1(y_true, y_score, threshold=0.5)


def evaluate_ranking(positives, lookup, score_fn, k=TOP_K):
    """For every real (positive) activity, rebuild its 24-hour candidate set and rank it
    with score_fn, then report Hit Rate@k and MAE.

    Builds every activity's candidates in one batch (rather than looping with
    candidates.build_candidates per activity) so this runs in seconds instead of minutes
    over tens of thousands of validation rows; a single live request still goes through
    candidates.py directly.
    """
    if len(positives) == 0:
        return {"hit_rate_at_3": 0.0, "mae_hours": 0.0}

    activities = positives.reset_index(drop=True)
    hours_per_day = 24

    candidates = activities.loc[activities.index.repeat(hours_per_day), REQUEST_COLUMNS].reset_index(drop=True)
    candidates["hour"] = list(range(hours_per_day)) * len(activities)
    candidates["activity"] = np.repeat(np.arange(len(activities)), hours_per_day)

    candidates = one_hot_category(apply_completion_rate(candidates, lookup))
    candidates["score"] = score_fn(candidates[FEATURE_COLUMNS])

    ranked_hours_list = (
        candidates.sort_values(["activity", "score"], ascending=[True, False])
        .groupby("activity")["hour"]
        .apply(list)
        .tolist()
    )
    true_hours = activities["hour"].tolist()

    return {
        "hit_rate_at_3": hit_rate_at_k(true_hours, ranked_hours_list, k=k),
        "mae_hours": mean_absolute_error_hours(true_hours, [ranked[0] for ranked in ranked_hours_list]),
    }


def evaluate_baseline(name, score_fn, val, val_positives, lookup, results):
    binary = evaluate_binary(val["label"], score_fn(val[FEATURE_COLUMNS]))
    ranking = evaluate_ranking(val_positives, lookup, score_fn)
    results[name] = {**binary, **ranking}


def main():
    train = pd.read_csv(PROCESSED_DIR / "train.csv")
    val = pd.read_csv(PROCESSED_DIR / "val.csv")
    lookup = pd.read_csv(PROCESSED_DIR / "completion_rate_lookup.csv")
    val_positives = val[val["label"] == 1]

    results = {}

    evaluate_baseline("completion_rate_only", completion_rate_score, val, val_positives, lookup, results)

    model = LogisticRegression(max_iter=1000)
    model.fit(train[FEATURE_COLUMNS], train["label"])
    logreg_score = lambda df: model.predict_proba(df)[:, 1]  # noqa: E731
    evaluate_baseline("logistic_regression", logreg_score, val, val_positives, lookup, results)

    out_path = ROOT / "models" / "baselines.json"
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(results, indent=2))

    for name, metrics in results.items():
        print(f"{name:>20}: F1={metrics['f1']:.3f}  Hit Rate@3={metrics['hit_rate_at_3']:.3f}  MAE={metrics['mae_hours']:.2f}h")
    print(f"\nSaved -> {out_path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()

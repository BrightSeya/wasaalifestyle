"""Fit and evaluate a research-only calibrated risk model from prepared rows."""

from __future__ import annotations

import argparse
import json
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    brier_score_loss,
    f1_score,
    precision_score,
    recall_score,
    roc_auc_score,
)
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler


FEATURES = [
    "category",
    "priority",
    "weekday",
    "scheduled_hour",
    "duration_minutes",
    "lead_hours",
    "prior_labeled_count",
    "prior_missed_rate",
    "prior_same_category_labeled_count",
    "prior_same_category_missed_rate",
]
CATEGORICAL = ["category", "priority"]
NUMERIC = [feature for feature in FEATURES if feature not in CATEGORICAL]
EXPECTED_COLUMNS = {"scheduled_at", *FEATURES, "label"}
REGULARIZATION_CANDIDATES = (0.1, 1.0, 10.0)


def _partition_by_time(
    dataset: pd.DataFrame, held_out_start: str
) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame, dict[str, str]]:
    data = dataset.copy()
    data["scheduled_at"] = pd.to_datetime(data["scheduled_at"], errors="raise", utc=True)
    distinct_times = sorted(data["scheduled_at"].unique())
    if len(distinct_times) < 3:
        raise ValueError("At least three distinct occurrence times are needed for train, validation, and held-out periods")

    test_cutoff = pd.to_datetime(held_out_start, errors="raise", utc=True)
    train_validation = data[data["scheduled_at"] < test_cutoff]
    test = data[data["scheduled_at"] >= test_cutoff]

    train_times = sorted(train_validation["scheduled_at"].unique())
    if len(train_times) < 2 or test.empty:
        raise ValueError("Chronological split cannot produce non-empty train, validation, and held-out periods")
    validation_index = max(1, int(len(train_times) * 0.8))
    if validation_index >= len(train_times):
        validation_index = len(train_times) - 1
    validation_cutoff = train_times[validation_index]
    train = train_validation[train_validation["scheduled_at"] < validation_cutoff]
    validation = train_validation[train_validation["scheduled_at"] >= validation_cutoff]
    if train.empty or validation.empty:
        raise ValueError("Chronological split cannot produce non-empty train and validation periods")

    return train, validation, test, {
        "train_before": validation_cutoff.isoformat(),
        "validation_from": validation_cutoff.isoformat(),
        "test_from": test_cutoff.isoformat(),
        "method": "chronological by distinct occurrence timestamp; tied timestamps stay in one partition",
    }


def _new_pipeline(c_value: float) -> Pipeline:
    preprocessing = ColumnTransformer(
        transformers=[
            ("category", OneHotEncoder(handle_unknown="ignore"), CATEGORICAL),
            (
                "numeric",
                Pipeline(
                    [
                        ("impute", SimpleImputer(strategy="constant", fill_value=0)),
                        ("scale", StandardScaler()),
                    ]
                ),
                NUMERIC,
            ),
        ],
        remainder="drop",
    )
    return Pipeline(
        [
            ("features", preprocessing),
            ("classifier", LogisticRegression(C=c_value, max_iter=2000, random_state=42)),
        ]
    )


def _f1_threshold(labels: np.ndarray, probabilities: np.ndarray) -> tuple[float, float]:
    candidates = sorted(set(float(value) for value in probabilities))
    best_threshold, best_f1 = 0.5, -1.0
    for threshold in candidates:
        predictions = (probabilities >= threshold).astype(int)
        score = f1_score(labels, predictions, zero_division=0)
        if score > best_f1 or (score == best_f1 and threshold > best_threshold):
            best_threshold, best_f1 = threshold, score
    return best_threshold, best_f1


def _platt_calibrator(validation_logits: np.ndarray, validation_labels: np.ndarray) -> LogisticRegression:
    calibrator = LogisticRegression(C=1.0, max_iter=2000, random_state=42)
    calibrator.fit(validation_logits.reshape(-1, 1), validation_labels)
    return calibrator


def _git_commit() -> str | None:
    try:
        root = Path(__file__).resolve().parents[3]
        result = subprocess.run(
            ["git", "-C", str(root), "rev-parse", "HEAD"],
            check=True,
            capture_output=True,
            text=True,
            timeout=5,
        )
        return result.stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return None


def train_and_evaluate(
    dataset: pd.DataFrame,
    *,
    evidence_type: str,
    baseline_report: dict[str, Any],
) -> tuple[dict[str, Any], dict[str, Any]]:
    if set(dataset.columns) != EXPECTED_COLUMNS:
        missing = EXPECTED_COLUMNS - set(dataset.columns)
        extra = set(dataset.columns) - EXPECTED_COLUMNS
        raise ValueError(f"Prepared dataset schema mismatch; missing={sorted(missing)}, extra={sorted(extra)}")
    if evidence_type not in {"approved-real", "synthetic"}:
        raise ValueError("Data provenance must be explicitly labeled")
    if evidence_type == "approved-real" and baseline_report.get("evidence_type") != "approved-real":
        raise ValueError("Real model training requires an approved-real preparation report")
    if evidence_type == "approved-real" and baseline_report.get("baseline", {}).get("status") != "computed":
        raise ValueError("The approved-real dataset does not have a usable baseline/chronological holdout")
    held_out_start = baseline_report.get("baseline", {}).get("split_at")
    if not held_out_start:
        raise ValueError("A shared baseline holdout cutoff is required for a valid comparison")

    labels = pd.to_numeric(dataset["label"], errors="raise")
    if not set(labels.unique()).issubset({0, 1}) or len(labels.unique()) != 2:
        raise ValueError("Both approved labels (completed=0 and missed=1) must be represented")
    ordered = dataset.copy()
    ordered["label"] = labels.astype(int)
    train, validation, test, split = _partition_by_time(ordered, held_out_start)

    for name, part in [("training", train), ("validation", validation), ("held-out", test)]:
        if part["label"].nunique() != 2:
            raise ValueError(f"{name} period must contain both labels for the requested evaluation")

    candidates = []
    for c_value in REGULARIZATION_CANDIDATES:
        candidate = _new_pipeline(c_value)
        candidate.fit(train[FEATURES], train["label"])
        validation_logits = candidate.decision_function(validation[FEATURES])
        candidates.append((roc_auc_score(validation["label"], validation_logits), -c_value, candidate))
    validation_auc, negative_c, model = max(candidates, key=lambda entry: (entry[0], entry[1]))
    selected_c = -negative_c

    validation_logits = model.decision_function(validation[FEATURES])
    calibrator = _platt_calibrator(validation_logits, validation["label"].to_numpy())
    validation_probabilities = calibrator.predict_proba(validation_logits.reshape(-1, 1))[:, 1]
    threshold, validation_f1 = _f1_threshold(validation["label"].to_numpy(), validation_probabilities)

    test_logits = model.decision_function(test[FEATURES])
    test_probabilities = calibrator.predict_proba(test_logits.reshape(-1, 1))[:, 1]
    test_predictions = (test_probabilities >= threshold).astype(int)
    test_labels = test["label"].to_numpy()

    train_validation_labels = pd.concat([train["label"], validation["label"]]).to_numpy()
    majority_label = int(np.mean(train_validation_labels) > 0.5)
    majority_probability = float(np.mean(train_validation_labels))
    baseline_predictions = np.full(len(test_labels), majority_label)
    baseline_test_accuracy = accuracy_score(test_labels, baseline_predictions)
    baseline_brier = brier_score_loss(test_labels, np.full(len(test_labels), majority_probability))

    model_card = {
        "model": "Wasaa commitment-risk logistic regression with validation-fitted Platt calibration",
        "approach_reference": "https://github.com/BrightSeya/scheduling (workflow reference only; its synthetic rows and booking-specific fields are not used)",
        "approach_reference": "https://github.com/BrightSeya/scheduling (workflow reference only; its synthetic rows and booking-specific fields are not used)",
        "evidence_type": evidence_type,
        "target": {"completed": 0, "missed": 1, "excluded": ["skipped", "cancelled", "unreported"]},
        "feature_list": FEATURES,
        "features_excluded": ["user identifiers", "schedule identifiers", "titles", "descriptions", "outcome timestamps", "post-event statuses"],
        "split": split,
        "selection": {
            "regularization_candidates": list(REGULARIZATION_CANDIDATES),
            "selected_c": selected_c,
            "validation_roc_auc": round(float(validation_auc), 6),
            "calibration": "Platt sigmoid fitted on validation logits and labels",
            "alert_threshold": round(float(threshold), 6),
            "validation_f1_at_threshold": round(float(validation_f1), 6),
        },
        "held_out_metrics": {
            "roc_auc": round(float(roc_auc_score(test_labels, test_probabilities)), 6),
            "precision": round(float(precision_score(test_labels, test_predictions, zero_division=0)), 6),
            "recall": round(float(recall_score(test_labels, test_predictions, zero_division=0)), 6),
            "f1": round(float(f1_score(test_labels, test_predictions, zero_division=0)), 6),
            "accuracy": round(float(accuracy_score(test_labels, test_predictions)), 6),
            "brier_score": round(float(brier_score_loss(test_labels, test_probabilities)), 6),
        },
        "baseline_comparison": {
            "method": "training-plus-validation majority class",
            "held_out_accuracy": round(float(baseline_test_accuracy), 6),
            "held_out_brier_score": round(float(baseline_brier), 6),
            "accuracy_difference_model_minus_baseline": round(
                float(accuracy_score(test_labels, test_predictions) - baseline_test_accuracy), 6
            ),
        },
        "counts": {
            "all_rows": len(ordered),
            "distinct_users": baseline_report.get("eligible_users"),
            "training_rows": len(train),
            "validation_rows": len(validation),
            "held_out_rows": len(test),
            "held_out_class_balance": {
                "completed": int(np.sum(test_labels == 0)),
                "missed": int(np.sum(test_labels == 1)),
            },
        },
        "limitations": [
            "Metrics are valid only for the stated data provenance and chronological test period.",
            "User-reported outcomes may be incomplete or inaccurate.",
            "Validation-selected threshold is a research candidate, not an approved product-alert threshold.",
            "User-facing predictions remain disabled pending data sufficiency, evaluation review, and product approval.",
        ],
        "user_facing_predictions_enabled": False,
        "evaluation_date_utc": datetime.now(timezone.utc).isoformat(),
        "git_commit": _git_commit(),
    }
    bundle = {"model": model, "calibrator": calibrator, "threshold": threshold, "features": FEATURES}
    return bundle, model_card


def predict_probability(bundle: dict[str, Any], feature_rows: pd.DataFrame) -> np.ndarray:
    if list(bundle["features"]) != FEATURES or set(feature_rows.columns) != set(FEATURES):
        raise ValueError("Inference rows must match the approved Wasaa feature schema")
    logits = bundle["model"].decision_function(feature_rows[FEATURES])
    return bundle["calibrator"].predict_proba(logits.reshape(-1, 1))[:, 1]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True, help="Private prepared feature CSV")
    parser.add_argument("--report", type=Path, required=True, help="Matching preparation JSON report")
    parser.add_argument("--artifact", type=Path, required=True, help="Private output model artifact")
    parser.add_argument("--model-card", type=Path, required=True, help="Private output model card JSON")
    args = parser.parse_args()

    try:
        baseline_report = json.loads(args.report.read_text(encoding="utf-8"))
        if baseline_report.get("evidence_type") != "approved-real":
            parser.error("Model artifact creation requires approved-real data; synthetic rows are test fixtures only")
        dataset = pd.read_csv(args.dataset)
        bundle, model_card = train_and_evaluate(
            dataset,
            evidence_type="approved-real",
            baseline_report=baseline_report,
        )
        args.artifact.parent.mkdir(parents=True, exist_ok=True)
        args.model_card.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump(bundle, args.artifact)
        args.model_card.write_text(json.dumps(model_card, indent=2) + "\n", encoding="utf-8")
    except (OSError, ValueError, KeyError) as error:
        parser.error(str(error))

    print(json.dumps(model_card, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

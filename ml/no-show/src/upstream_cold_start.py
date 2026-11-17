"""Cold-start model trained on the synthetic bookings bundled from scheduling.

This is a demonstration prior, not evidence of Wasaa-user performance. It keeps
the upstream label and training split, then maps only compatible Wasaa signals.
Fields Wasaa does not collect use training-set neutral values; unmatched Wasaa
categories are encoded as unknown instead of being guessed into service types.
"""

from __future__ import annotations

import hashlib
import argparse
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    brier_score_loss,
    f1_score,
    precision_recall_curve,
    precision_score,
    recall_score,
    roc_auc_score,
)
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler


UPSTREAM_REPOSITORY = "https://github.com/BrightSeya/scheduling"
UPSTREAM_MODEL_SOURCE = f"{UPSTREAM_REPOSITORY}/blob/main/app/model.py"
UPSTREAM_FEATURE_SOURCE = f"{UPSTREAM_REPOSITORY}/blob/main/app/features.py"
UPSTREAM_COMMIT = "c40599197b054a5248461c0ff9a2532fd3e4da1c"
UPSTREAM_DATA_PATH = Path(__file__).resolve().parents[1] / "data" / "upstream" / "bookings.csv"
UPSTREAM_DATA_SHA256 = "a6c0b949d20d988583bbda03b91e0b506dc8fe3f2a3b5fa1163d12388bc6d98a"
UPSTREAM_LABEL = "did_not_show"
UPSTREAM_NUMERIC = [
    "lead_time_hours",
    "scheduled_hour",
    "scheduled_dayofweek",
    "is_weekend",
    "prior_bookings",
    "is_first_booking",
    "prior_no_shows",
    "prior_no_show_rate",
    "total_amount_kes",
    "rating_average",
    "is_verified",
]
UPSTREAM_CATEGORICAL = ["category"]
UPSTREAM_FEATURES = [*UPSTREAM_NUMERIC, *UPSTREAM_CATEGORICAL]
WASAA_FEATURES = [
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
MODEL_TYPE = "upstream-synthetic-cold-start-v1"


def _verify_data(path: Path) -> None:
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if digest != UPSTREAM_DATA_SHA256:
        raise ValueError("Upstream synthetic bookings failed the pinned SHA-256 check")


def _upstream_split(dataset: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    ordered = dataset.sort_values("created_at", kind="stable").reset_index(drop=True)
    train_end = int(len(ordered) * 0.75)
    train, test = ordered.iloc[:train_end], ordered.iloc[train_end:]
    fit_end = int(len(train) * (1 - 0.15))
    fit, validation = train.iloc[:fit_end], train.iloc[fit_end:]
    if fit.empty or validation.empty or test.empty:
        raise ValueError("Pinned upstream data cannot produce its chronological train/validation/test split")
    for name, part in (("fit", fit), ("validation", validation), ("test", test)):
        if part[UPSTREAM_LABEL].nunique() != 2:
            raise ValueError(f"Upstream {name} split must contain both outcome classes")
    return fit, validation, test


def _pipeline() -> Pipeline:
    prep = ColumnTransformer([
        ("num", StandardScaler(), UPSTREAM_NUMERIC),
        ("cat", OneHotEncoder(handle_unknown="ignore"), UPSTREAM_CATEGORICAL),
    ])
    return Pipeline([("prep", prep), ("clf", LogisticRegression(max_iter=2000))])


def _logit(probabilities: np.ndarray) -> np.ndarray:
    clipped = np.clip(np.asarray(probabilities, dtype=float), 1e-6, 1 - 1e-6)
    return np.log(clipped / (1 - clipped))


def _platt(probabilities: np.ndarray, labels: np.ndarray) -> LogisticRegression:
    return LogisticRegression(C=1e6, max_iter=1000).fit(_logit(probabilities).reshape(-1, 1), labels)


def _f1_threshold(labels: np.ndarray, probabilities: np.ndarray) -> float:
    precision, recall, thresholds = precision_recall_curve(labels, probabilities)
    scores = 2 * precision[:-1] * recall[:-1] / np.maximum(precision[:-1] + recall[:-1], 1e-12)
    return float(thresholds[int(np.argmax(scores))])


def train_upstream_cold_start(path: Path = UPSTREAM_DATA_PATH) -> tuple[dict[str, Any], dict[str, Any]]:
    """Fit the upstream Logistic Regression + Platt model on its pinned synthetic data."""
    _verify_data(path)
    data = pd.read_csv(path, parse_dates=["created_at"])
    required = {"created_at", UPSTREAM_LABEL, *UPSTREAM_FEATURES, "booking_status"}
    if not required.issubset(data.columns) or not data["is_synthetic"].astype(bool).all():
        raise ValueError("Pinned upstream CSV schema/provenance check failed")

    fit, validation, test = _upstream_split(data)
    model = _pipeline().fit(fit[UPSTREAM_FEATURES], fit[UPSTREAM_LABEL].astype(int))
    validation_raw = model.predict_proba(validation[UPSTREAM_FEATURES])[:, 1]
    calibrator = _platt(validation_raw, validation[UPSTREAM_LABEL].to_numpy(dtype=int))
    validation_probabilities = calibrator.predict_proba(_logit(validation_raw).reshape(-1, 1))[:, 1]
    threshold = _f1_threshold(validation[UPSTREAM_LABEL].to_numpy(dtype=int), validation_probabilities)

    test_raw = model.predict_proba(test[UPSTREAM_FEATURES])[:, 1]
    test_probabilities = calibrator.predict_proba(_logit(test_raw).reshape(-1, 1))[:, 1]
    test_labels = test[UPSTREAM_LABEL].to_numpy(dtype=int)
    test_predictions = test_probabilities >= threshold

    # Use the scaler's learned centers for fields Wasaa does not collect. This is a
    # neutral synthetic-training reference, not an inferred user/provider value.
    scaler = model.named_steps["prep"].named_transformers_["num"]
    neutral = dict(zip(UPSTREAM_NUMERIC, map(float, scaler.mean_), strict=True))
    bundle = {
        "model_type": MODEL_TYPE,
        "model": model,
        "calibrator": calibrator,
        "threshold": float(threshold),
        "features": WASAA_FEATURES,
        "source_features": UPSTREAM_FEATURES,
        "neutral_values": neutral,
        "version": f"upstream-synthetic-{UPSTREAM_COMMIT[:12]}",
    }
    card = {
        "model": "BrightSeya/scheduling synthetic Logistic Regression + Platt cold-start",
        "model_type": MODEL_TYPE,
        "evidence_type": "synthetic-upstream",
        "model_source": UPSTREAM_MODEL_SOURCE,
        "feature_source": UPSTREAM_FEATURE_SOURCE,
        "upstream_commit": UPSTREAM_COMMIT,
        "dataset_path": "ml/no-show/data/upstream/bookings.csv",
        "dataset_sha256": UPSTREAM_DATA_SHA256,
        "target": "Synthetic upstream did_not_show; includes NO_SHOW and CANCELLED outcomes.",
        "adaptation": {
            "lead_time_hours": "Wasaa lead_hours",
            "scheduled_hour": "Wasaa scheduled_hour",
            "scheduled_dayofweek": "Wasaa weekday (Monday=0)",
            "is_weekend": "derived from Wasaa weekday",
            "prior_bookings": "Wasaa prior_labeled_count",
            "is_first_booking": "derived from Wasaa prior_labeled_count",
            "prior_no_shows": "rounded Wasaa prior_missed_rate multiplied by prior_labeled_count",
            "prior_no_show_rate": "Wasaa prior_missed_rate, or 0 for no history",
            "total_amount_kes": "upstream fit-set scaled center; Wasaa does not collect this field",
            "rating_average": "upstream fit-set scaled center; Wasaa does not collect this field",
            "is_verified": "upstream fit-set scaled center; Wasaa does not collect this field",
            "category": "unmapped Wasaa categories are passed as unknown and receive no category coefficient",
            "unused_wasaa_fields": ["priority", "duration_minutes", "prior_same_category_labeled_count", "prior_same_category_missed_rate"],
        },
        "split": {
            "method": "upstream chronological created_at split",
            "fit_rows": len(fit),
            "validation_rows": len(validation),
            "held_out_rows": len(test),
        },
        "selection": {
            "classifier": "upstream LogisticRegression(max_iter=2000)",
            "calibration": "upstream Platt sigmoid on raw validation probability logits (C=1e6)",
            "validation_f1_threshold": float(threshold),
        },
        "synthetic_held_out_metrics": {
            "roc_auc": float(roc_auc_score(test_labels, test_probabilities)),
            "brier_score": float(brier_score_loss(test_labels, test_probabilities)),
            "precision": float(precision_score(test_labels, test_predictions, zero_division=0)),
            "recall": float(recall_score(test_labels, test_predictions, zero_division=0)),
            "f1": float(f1_score(test_labels, test_predictions, zero_division=0)),
        },
        "limitations": [
            "All training and held-out data are synthetic and do not establish Wasaa-user predictive performance.",
            "The source target includes late cancellations while Wasaa's explicit missed label does not.",
            "The Wasaa feature adapter uses neutral source-training centers for amount, rating and verification fields not collected by Wasaa.",
            "The source-selected threshold is not validated for Wasaa and must not be treated as a real-user alert threshold.",
            "Wasaa users' explicit prior outcomes affect mapped history features, but do not refit the model weights.",
        ],
        "user_facing_predictions_enabled": True,
        "user_facing_prediction_policy": "Requires the individual user's explicit Wasaa consent; all estimates are labelled prototype-only.",
        "model_version": f"upstream-synthetic-{UPSTREAM_COMMIT[:12]}",
        "evaluation_date_utc": datetime.now(timezone.utc).isoformat(),
    }
    return bundle, card


def adapt_wasaa_features(bundle: dict[str, Any], feature_rows: pd.DataFrame) -> pd.DataFrame:
    if list(bundle.get("features", [])) != WASAA_FEATURES or set(feature_rows.columns) != set(WASAA_FEATURES):
        raise ValueError("Inference rows must match the approved Wasaa request schema")
    rows = pd.DataFrame(index=feature_rows.index)
    rows["lead_time_hours"] = pd.to_numeric(feature_rows["lead_hours"])
    rows["scheduled_hour"] = pd.to_numeric(feature_rows["scheduled_hour"])
    rows["scheduled_dayofweek"] = pd.to_numeric(feature_rows["weekday"])
    rows["is_weekend"] = feature_rows["weekday"].isin([5, 6]).astype(int)
    history_count = pd.to_numeric(feature_rows["prior_labeled_count"])
    missed_rate = pd.to_numeric(feature_rows["prior_missed_rate"].fillna(0))
    rows["prior_bookings"] = history_count
    rows["is_first_booking"] = history_count.eq(0).astype(int)
    rows["prior_no_shows"] = np.rint(history_count * missed_rate)
    rows["prior_no_show_rate"] = missed_rate
    for field in ("total_amount_kes", "rating_average", "is_verified"):
        rows[field] = bundle["neutral_values"][field]
    # Wasaa lifestyle categories are not upstream service categories. Unknown is
    # deliberately ignored by the fitted OneHotEncoder instead of guessed.
    rows["category"] = "__WASAA_CATEGORY_UNMAPPED__"
    return rows[UPSTREAM_FEATURES]


def predict_wasaa_probability(bundle: dict[str, Any], feature_rows: pd.DataFrame) -> np.ndarray:
    source_rows = adapt_wasaa_features(bundle, feature_rows)
    raw = bundle["model"].predict_proba(source_rows)[:, 1]
    return bundle["calibrator"].predict_proba(_logit(raw).reshape(-1, 1))[:, 1]


def personalize_upstream_probability(probability: float, feature_row: dict[str, Any]) -> float:
    """Use same-category Wasaa outcomes to adapt the synthetic cold-start estimate.

    The upstream model already consumes overall prior history. This second-stage
    adjustment uses only the same-category rate, which the upstream feature schema
    cannot represent, and shrinks sparse counts toward the cold-start estimate.
    """
    count = int(feature_row["prior_same_category_labeled_count"])
    rate = feature_row["prior_same_category_missed_rate"]
    if count == 0 or rate is None:
        return float(probability)

    prior_strength = 8.0
    return float((prior_strength * probability + count * float(rate)) / (prior_strength + count))


def explain_wasaa_factors(bundle: dict[str, Any], feature_row: dict[str, Any], limit: int = 3) -> list[dict[str, Any]]:
    adapted = adapt_wasaa_features(bundle, pd.DataFrame([feature_row]))
    model = bundle["model"]
    prep = model.named_steps["prep"]
    transformed = prep.transform(adapted)
    contributions = np.asarray(transformed.toarray() if hasattr(transformed, "toarray") else transformed)[0]
    contributions = contributions * model.named_steps["clf"].coef_[0]
    names = prep.get_feature_names_out()
    source_to_wasaa = {
        "lead_time_hours": ("lead_hours", feature_row["lead_hours"]),
        "scheduled_hour": ("scheduled_hour", feature_row["scheduled_hour"]),
        "scheduled_dayofweek": ("weekday", feature_row["weekday"]),
        "is_weekend": ("weekday", feature_row["weekday"]),
        "prior_bookings": ("prior_labeled_count", feature_row["prior_labeled_count"]),
        "is_first_booking": ("prior_labeled_count", feature_row["prior_labeled_count"]),
        "prior_no_shows": ("prior_missed_rate", feature_row["prior_missed_rate"]),
        "prior_no_show_rate": ("prior_missed_rate", feature_row["prior_missed_rate"]),
    }
    grouped: dict[str, float] = {}
    display: dict[str, Any] = {}
    for name, contribution in zip(names, contributions, strict=True):
        source_feature = name.split("__", 1)[-1]
        mapping = source_to_wasaa.get(source_feature)
        if mapping is None or contribution <= 0:
            continue
        target, value = mapping
        grouped[target] = grouped.get(target, 0.0) + float(contribution)
        display[target] = value
    ranked = sorted(grouped.items(), key=lambda item: item[1], reverse=True)[:limit]
    return [
        {"feature": name, "value": display[name], "log_odds_contribution": round(value, 4)}
        for name, value in ranked
    ]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifact", type=Path, required=True, help="Private output model artifact")
    parser.add_argument("--model-card", type=Path, required=True, help="Private output model card JSON")
    args = parser.parse_args()
    try:
        import joblib

        bundle, card = train_upstream_cold_start()
        args.artifact.parent.mkdir(parents=True, exist_ok=True)
        args.model_card.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump(bundle, args.artifact)
        args.model_card.write_text(json.dumps(card, indent=2) + "\n", encoding="utf-8")
    except (OSError, ValueError) as error:
        parser.error(str(error))
    print(json.dumps(card, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

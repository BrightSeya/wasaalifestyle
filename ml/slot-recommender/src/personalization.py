"""Fit an isolated, on-demand recommendation model from one user's outcomes."""

import math

import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import brier_score_loss
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

from features import CATEGORY_COLUMNS, FEATURE_COLUMNS, apply_completion_rate, one_hot_category
from metrics import best_f1_threshold

MIN_PERSONAL_OUTCOMES = 120
MIN_OUTCOMES_PER_CLASS = 20
MIN_VALIDATION_OUTCOMES = 30
PERSONAL_FEATURE_COLUMNS = [
    "duration_min",
    "historical_completion_rate",
    "hour_sin",
    "hour_cos",
    "day_sin",
    "day_cos",
    *CATEGORY_COLUMNS,
]


def build_personal_training_data(history, lookup):
    """Turn validated per-user outcome examples into model features, labels, and weights."""
    examples = pd.DataFrame(history)
    examples = apply_completion_rate(examples, lookup)
    examples = one_hot_category(examples)

    return (
        examples[FEATURE_COLUMNS],
        examples["label"].to_numpy(dtype=int),
        examples["weight"].to_numpy(dtype=float),
    )


def personal_model_features(features):
    """Keep reliable personal signals and represent weekday/hour as cyclic values."""
    transformed = features.copy()
    hour_angle = 2 * np.pi * transformed["hour"] / 24
    day_angle = 2 * np.pi * transformed["day_of_week"] / 7
    transformed["hour_sin"] = np.sin(hour_angle)
    transformed["hour_cos"] = np.cos(hour_angle)
    transformed["day_sin"] = np.sin(day_angle)
    transformed["day_cos"] = np.cos(day_angle)

    return transformed[PERSONAL_FEATURE_COLUMNS]


def fit_personal_model(history, lookup, population_model):
    """Return a user's model only when it beats the population model on held-out outcomes."""
    if len(history) < MIN_PERSONAL_OUTCOMES:
        return None, {"status": "insufficient_history", "outcomes": len(history)}

    population_features, labels, weights = build_personal_training_data(history, lookup)
    features = personal_model_features(population_features)
    class_counts = np.bincount(labels, minlength=2)
    if np.any(class_counts < MIN_OUTCOMES_PER_CLASS):
        return None, {"status": "insufficient_class_history", "outcomes": len(history)}

    validation_size = min(
        len(history) - 2 * MIN_OUTCOMES_PER_CLASS,
        max(MIN_VALIDATION_OUTCOMES, math.ceil(len(history) * 0.2)),
    )
    chronological_indices = np.argsort(
        np.asarray([example["occurred_at"] for example in history]),
        kind="stable",
    )
    train_indices = chronological_indices[:-validation_size]
    validation_indices = chronological_indices[-validation_size:]
    if np.any(np.bincount(labels[train_indices], minlength=2) < 10) or np.any(
        np.bincount(labels[validation_indices], minlength=2) < 5
    ):
        return None, {
            "status": "insufficient_recent_class_history",
            "outcomes": len(history),
        }

    personal = make_pipeline(
        StandardScaler(),
        LogisticRegression(C=0.1, max_iter=1000, random_state=42),
    )
    personal.fit(
        features.iloc[train_indices],
        labels[train_indices],
        logisticregression__sample_weight=weights[train_indices],
    )

    validation_features = features.iloc[validation_indices]
    validation_population_features = population_features.iloc[validation_indices]
    validation_labels = labels[validation_indices]
    validation_weights = weights[validation_indices]
    personal_scores = personal.predict_proba(validation_features)[:, 1]
    population_scores = population_model.predict_proba(validation_population_features)[:, 1]
    personal_brier = brier_score_loss(
        validation_labels, personal_scores, sample_weight=validation_weights,
    )
    population_brier = brier_score_loss(
        validation_labels, population_scores, sample_weight=validation_weights,
    )

    if personal_brier >= population_brier:
        return None, {
            "status": "population_model_performed_better",
            "outcomes": len(history),
            "personal_brier": float(personal_brier),
            "population_brier": float(population_brier),
        }

    personal_threshold = best_f1_threshold(
        validation_labels,
        personal_scores,
        sample_weight=validation_weights,
    )
    personal.fit(
        features,
        labels,
        logisticregression__sample_weight=weights,
    )
    return personal, {
        "status": "active",
        "outcomes": len(history),
        "personal_brier": float(personal_brier),
        "population_brier": float(population_brier),
        "decision_threshold": personal_threshold,
    }

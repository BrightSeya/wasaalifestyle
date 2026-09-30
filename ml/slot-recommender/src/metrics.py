"""CRISP-DM Evaluation: metrics for the slot recommender (proposal Section 3.2.5).

Precision/Recall/F1 score the binary suitable/not-suitable classification at a threshold.
Hit Rate@K and MAE score *ranking* quality: for each real activity, was its real hour
among the model's top-K candidate hours (see candidates.py), and how far off was the
single best-ranked hour, measured around a 24-hour clock.
"""

import numpy as np
from sklearn.metrics import f1_score, precision_score, recall_score

HOURS_IN_DAY = 24


def precision_recall_f1(y_true, y_score, threshold=0.5):
    """y_true: 0/1 labels. y_score: predicted probability of being suitable."""
    y_pred = (np.asarray(y_score) >= threshold).astype(int)

    return {
        "precision": float(precision_score(y_true, y_pred, zero_division=0)),
        "recall": float(recall_score(y_true, y_pred, zero_division=0)),
        "f1": float(f1_score(y_true, y_pred, zero_division=0)),
    }


def circular_hour_distance(a, b):
    """Shortest distance between two hours on a 24-hour clock, e.g. 23 and 1 -> 2."""
    diff = abs(int(a) - int(b)) % HOURS_IN_DAY
    return min(diff, HOURS_IN_DAY - diff)


def hit_rate_at_k(true_hours, ranked_hours_list, k=3):
    """Share of activities whose real hour is among the top k of its ranked candidate hours.

    true_hours: the real hour for each activity.
    ranked_hours_list: for each activity, its candidate hours best-first (see candidates.rank_hours).
    """
    if len(true_hours) == 0:
        return 0.0

    hits = [true_hour in ranked[:k] for true_hour, ranked in zip(true_hours, ranked_hours_list)]
    return float(np.mean(hits))


def mean_absolute_error_hours(true_hours, top_hours):
    """Average circular distance, in hours, between each activity's real hour and its top-ranked hour."""
    if len(true_hours) == 0:
        return 0.0

    distances = [circular_hour_distance(t, p) for t, p in zip(true_hours, top_hours)]
    return float(np.mean(distances))

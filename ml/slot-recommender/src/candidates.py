"""CRISP-DM Evaluation: build the 24 candidate hour rows for one scheduling request,
exactly as the future Flask /predict endpoint will (Section 3.2.6 of the proposal).

A request describes a task or activity but not a specific time: category, day of week,
duration, how busy the day already is, and the gap since the same category last happened.
Scoring all 24 hours and ranking them is how the model is used in both evaluation and
production, so this module is shared by the baselines (#39), the trained model (#40) and,
later, the API.
"""

import pandas as pd

from features import FEATURE_COLUMNS, apply_completion_rate, one_hot_category

REQUEST_FIELDS = [
    "category",
    "day_of_week",
    "duration_min",
    "schedule_density",
    "gap_since_last_same_category_hrs",
]

HOURS_IN_DAY = 24


def build_candidates(request, lookup):
    """A 24-row DataFrame (hour 0-23) with FEATURE_COLUMNS, one row per candidate hour.

    request: dict with REQUEST_FIELDS. Every field is repeated across the 24 rows; only
    hour (and the historical_completion_rate it implies) changes.
    """
    missing = [field for field in REQUEST_FIELDS if field not in request]
    if missing:
        raise ValueError(f"Missing request fields: {missing}")

    candidates = pd.DataFrame([request] * HOURS_IN_DAY)
    candidates["hour"] = range(HOURS_IN_DAY)

    candidates = apply_completion_rate(candidates, lookup)
    candidates = one_hot_category(candidates)

    return candidates[FEATURE_COLUMNS]


def score_candidates(request, lookup, score_fn, user_feedback=None, excluded_hours=None):
    """Candidates for request, with a 'score' column, ranked best (most suitable) first.

    score_fn: any callable(DataFrame[FEATURE_COLUMNS]) -> array-like of scores, e.g.
    `model.predict_proba(df)[:, 1]` for a trained classifier, or a plain function for a
    baseline such as "rank by historical_completion_rate".
    """
    candidates = build_candidates(request, lookup)
    scored = candidates.copy()
    scored["score"] = score_fn(candidates)

    # ATUS remains the cold-start prior. Hierarchical shrinkage lets personal
    # outcomes influence rankings gradually without fitting a model to a tiny
    # and noisy per-user sample.
    if user_feedback:
        prior_strength = 12.0
        category_strength = 6.0
        weekday = request["day_of_week"]
        for index, row in scored.iterrows():
            feedback = user_feedback.get(f"{weekday}:{int(row['hour'])}")
            if not feedback:
                continue

            base_score = float(row["score"])
            other_total = float(feedback["other_total"])
            other_completed = float(feedback["other_completed"])
            user_rate = (prior_strength * base_score + other_completed) / (prior_strength + other_total)

            category_total = float(feedback["category_total"])
            category_completed = float(feedback["category_completed"])
            scored.at[index, "score"] = (
                category_strength * user_rate + category_completed
            ) / (category_strength + category_total)

    if excluded_hours:
        scored = scored[~scored["hour"].isin(excluded_hours)]

    return scored.sort_values("score", ascending=False).reset_index(drop=True)


def rank_hours(request, lookup, score_fn, user_feedback=None, excluded_hours=None):
    """All 24 candidate hours, best first."""
    return score_candidates(request, lookup, score_fn, user_feedback, excluded_hours)["hour"].tolist()


def top_k_hours(request, lookup, score_fn, k=3, user_feedback=None, excluded_hours=None):
    """The top-k candidate hours as the API would return them: [{hour, score}, ...]."""
    ranked = score_candidates(request, lookup, score_fn, user_feedback, excluded_hours)
    return ranked.head(k)[["hour", "score"]].to_dict("records")

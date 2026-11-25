import numpy as np

from personalization import (
    build_personal_training_data,
    fit_personal_model,
    personal_model_features,
)
from features import fit_completion_rate


def personal_history():
    history = []
    for sample in range(240):
        hour = sample % 24
        history.append({
            "category": "exercise",
            "day_of_week": sample % 7,
            "hour": hour,
            "duration_min": 45,
            "schedule_density": sample % 4,
            "gap_since_last_same_category_hrs": 24.0 + sample % 12,
            "label": int(hour >= 12),
            "weight": 1.0,
            "occurred_at": 1_700_000_000 + (sample * 3600),
        })
    return history


class OppositePopulationModel:
    def predict_proba(self, features):
        positive = np.where(features["hour"].to_numpy() < 12, 0.9, 0.1)
        return np.column_stack((1 - positive, positive))


class MatchingPopulationModel:
    def predict_proba(self, features):
        positive = np.where(features["hour"].to_numpy() >= 12, 0.99, 0.01)
        return np.column_stack((1 - positive, positive))


def lookup_table():
    import pandas as pd

    return fit_completion_rate(pd.DataFrame({
        "category": ["exercise"] * 24,
        "hour": list(range(24)),
    }))


def test_personal_model_activates_when_it_beats_the_population_model():
    model, evaluation = fit_personal_model(
        personal_history(),
        lookup_table(),
        OppositePopulationModel(),
    )

    assert model is not None
    assert evaluation["status"] == "active"
    assert evaluation["personal_brier"] < evaluation["population_brier"]
    assert 0 <= evaluation["decision_threshold"] <= 1
    features, _, _ = build_personal_training_data(personal_history()[:1], lookup_table())
    assert model.predict_proba(personal_model_features(features)).shape == (1, 2)


def test_population_model_remains_active_when_personal_model_does_not_improve():
    model, evaluation = fit_personal_model(
        personal_history(),
        lookup_table(),
        MatchingPopulationModel(),
    )

    assert model is None
    assert evaluation["status"] == "population_model_performed_better"
    assert evaluation["personal_brier"] >= evaluation["population_brier"]


def test_personal_model_waits_until_minimum_history_is_available():
    model, evaluation = fit_personal_model(
        personal_history()[:119],
        lookup_table(),
        OppositePopulationModel(),
    )

    assert model is None
    assert evaluation == {"status": "insufficient_history", "outcomes": 119}

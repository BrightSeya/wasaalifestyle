import importlib.util
import json
import subprocess
import sys
from datetime import datetime, timedelta
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import pytest


SOURCE_DIR = Path(__file__).parents[1] / "src"
TRAIN_SPEC = importlib.util.spec_from_file_location("train_model", SOURCE_DIR / "train_model.py")
train_model = importlib.util.module_from_spec(TRAIN_SPEC)
assert TRAIN_SPEC.loader is not None
sys.modules[TRAIN_SPEC.name] = train_model
TRAIN_SPEC.loader.exec_module(train_model)

PREPARE_SPEC = importlib.util.spec_from_file_location("prepare_dataset", SOURCE_DIR / "prepare_dataset.py")
prepare_dataset = importlib.util.module_from_spec(PREPARE_SPEC)
assert PREPARE_SPEC.loader is not None
sys.modules[PREPARE_SPEC.name] = prepare_dataset
PREPARE_SPEC.loader.exec_module(prepare_dataset)


def synthetic_dataset(size=100):
    start = datetime(2026, 1, 1, 12)
    rows = []
    for index in range(size):
        rows.append(
            {
                "scheduled_at": (start + timedelta(days=index)).isoformat(sep=" "),
                "category": "work" if index % 2 else "exercise",
                "priority": "high" if index % 3 == 0 else "medium",
                "weekday": index % 7,
                "scheduled_hour": 12,
                "duration_minutes": 45.0,
                "lead_hours": 24.0 + (index % 5),
                "prior_labeled_count": index % 12,
                "prior_missed_rate": (index % 4) / 4 if index else None,
                "prior_same_category_labeled_count": index % 6,
                "prior_same_category_missed_rate": (index % 3) / 3 if index else None,
                "label": int(index % 3 == 0 or index % 7 == 2),
            }
        )
    return pd.DataFrame(rows)


def baseline_report(dataset):
    rows = [
        prepare_dataset.OccurrenceRow(
            schedule_id=str(index),
            user_id="synthetic-user",
            start=datetime.fromisoformat(row.scheduled_at),
            end=datetime.fromisoformat(row.scheduled_at) + timedelta(minutes=45),
            created_at=datetime.fromisoformat(row.scheduled_at) - timedelta(hours=24),
            category=row.category,
            priority=row.priority,
            outcome="missed" if row.label else "completed",
            recorded_at=datetime.fromisoformat(row.scheduled_at) + timedelta(hours=1),
        )
        for index, row in dataset.iterrows()
    ]
    _, report = prepare_dataset.prepare(rows, evidence_type="synthetic")
    return report


def test_train_calibrate_evaluate_and_save_a_synthetic_test_artifact(tmp_path):
    dataset = synthetic_dataset()
    report = baseline_report(dataset)

    bundle, model_card = train_model.train_and_evaluate(
        dataset,
        evidence_type="synthetic",
        baseline_report=report,
    )

    assert report["baseline"]["status"] == "computed"
    assert model_card["evidence_type"] == "synthetic"
    assert model_card["user_facing_predictions_enabled"] is False
    assert model_card["split"]["test_from"] == pd.to_datetime(report["baseline"]["split_at"], utc=True).isoformat()
    assert set(model_card["held_out_metrics"]) == {"roc_auc", "precision", "recall", "f1", "accuracy", "brier_score"}
    assert set(model_card["baseline_comparison"]) >= {"held_out_accuracy", "held_out_brier_score"}

    artifact = tmp_path / "synthetic-only.joblib"
    joblib.dump(bundle, artifact)
    restored = joblib.load(artifact)
    probabilities = train_model.predict_probability(restored, dataset[train_model.FEATURES].iloc[:5])
    assert len(probabilities) == 5
    assert np.isfinite(probabilities).all()
    assert ((probabilities >= 0) & (probabilities <= 1)).all()


def test_training_rejects_post_event_columns():
    dataset = synthetic_dataset()
    dataset["final_activity_status"] = "missed"

    with pytest.raises(ValueError, match="schema mismatch"):
        train_model.train_and_evaluate(
            dataset,
            evidence_type="synthetic",
            baseline_report=baseline_report(synthetic_dataset()),
        )


def test_real_model_requires_approved_dataset_and_a_computed_baseline():
    dataset = synthetic_dataset()
    report = baseline_report(dataset)
    report["evidence_type"] = "synthetic"

    with pytest.raises(ValueError, match="approved-real preparation report"):
        train_model.train_and_evaluate(dataset, evidence_type="approved-real", baseline_report=report)


def test_cli_refuses_to_create_persistent_artifact_from_synthetic_data(tmp_path):
    dataset = synthetic_dataset()
    report = baseline_report(dataset)
    dataset_path = tmp_path / "synthetic.csv"
    report_path = tmp_path / "synthetic-report.json"
    artifact_path = tmp_path / "must-not-exist.joblib"
    card_path = tmp_path / "must-not-exist.json"
    dataset.to_csv(dataset_path, index=False)
    report_path.write_text(json.dumps(report), encoding="utf-8")

    result = subprocess.run(
        [
            sys.executable,
            str(SOURCE_DIR / "train_model.py"),
            "--dataset",
            str(dataset_path),
            "--report",
            str(report_path),
            "--artifact",
            str(artifact_path),
            "--model-card",
            str(card_path),
        ],
        text=True,
        capture_output=True,
        check=False,
    )

    assert result.returncode != 0
    assert "requires approved-real data" in result.stderr
    assert not artifact_path.exists()
    assert not card_path.exists()

import csv
import importlib.util
import io
import json
import subprocess
import sys
from datetime import datetime, timedelta
from pathlib import Path

import pytest


MODULE_PATH = Path(__file__).parents[1] / "src" / "prepare_dataset.py"
SPEC = importlib.util.spec_from_file_location("prepare_dataset", MODULE_PATH)
prepare_dataset = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = prepare_dataset
SPEC.loader.exec_module(prepare_dataset)


def synthetic_rows(count=10):
    rows = []
    start_day = datetime(2026, 1, 1)
    for index in range(count):
        start = start_day + timedelta(days=index, hours=12)
        end = start + timedelta(minutes=30)
        rows.append(
            {
                "schedule_id": f"synthetic-{index}",
                "user_id": "synthetic-user",
                "occurrence_start": start.isoformat(sep=" "),
                "occurrence_end": end.isoformat(sep=" "),
                "schedule_created_at": (start - timedelta(hours=4)).isoformat(sep=" "),
                "category": "work",
                "priority": "medium",
                "outcome": "missed" if index in {1, 5, 9} else "completed",
                "recorded_at": (end + timedelta(minutes=5)).isoformat(sep=" "),
            }
        )
    return rows


def load_rows(tmp_path, rows, extra_columns=None):
    input_path = tmp_path / "synthetic.csv"
    columns = list(prepare_dataset.INPUT_COLUMNS)
    if extra_columns:
        columns += extra_columns
    with input_path.open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=columns)
        writer.writeheader()
        for row in rows:
            writer.writerow({**row, **{column: "" for column in (extra_columns or [])}})
    return prepare_dataset.read_rows(input_path)


def test_prepare_applies_approved_labels_and_chronological_majority_baseline(tmp_path):
    source = synthetic_rows()
    source.extend(
        [
            {**synthetic_rows(1)[0], "schedule_id": "synthetic-skip", "outcome": "skipped"},
            {**synthetic_rows(1)[0], "schedule_id": "synthetic-cancel", "outcome": "cancelled"},
            {**synthetic_rows(1)[0], "schedule_id": "synthetic-unreported", "outcome": "", "recorded_at": ""},
        ]
    )
    rows, _ = load_rows(tmp_path, source)

    dataset, report = prepare_dataset.prepare(rows, evidence_type="synthetic")

    assert len(dataset) == 10
    assert report["evidence_type"] == "synthetic"
    assert report["eligible_users"] == 1
    assert report["class_balance"] == {"completed": 7, "missed": 3}
    assert report["excluded_rows"] == {"cancelled": 1, "skipped": 1, "unreported": 1}
    assert report["baseline"]["status"] == "computed"
    assert report["baseline"]["train_rows"] == 8
    assert report["baseline"]["test_rows"] == 2
    assert report["baseline"]["test_accuracy"] == 0.5
    assert report["user_facing_predictions_enabled"] is False
    assert not {"schedule_id", "user_id", "recorded_at"} & set(dataset[0])


def test_history_features_use_only_prior_outcomes_recorded_before_target_time(tmp_path):
    source = synthetic_rows(3)
    # Move the second and third occurrences to the afternoon so the previous
    # occurrence's recorded outcome is available before the next one begins.
    for index, row in enumerate(source):
        start = datetime(2026, 1, 1) + timedelta(days=index, hours=14)
        end = start + timedelta(minutes=30)
        row["occurrence_start"] = start.isoformat(sep=" ")
        row["occurrence_end"] = end.isoformat(sep=" ")
        row["schedule_created_at"] = (start - timedelta(hours=4)).isoformat(sep=" ")
        row["recorded_at"] = (end + timedelta(minutes=5)).isoformat(sep=" ")
    rows, _ = load_rows(tmp_path, source)

    dataset, _ = prepare_dataset.prepare(rows, evidence_type="synthetic")

    assert dataset[0]["prior_labeled_count"] == 0
    assert dataset[0]["prior_missed_rate"] is None
    assert dataset[1]["prior_labeled_count"] == 1
    assert dataset[1]["prior_missed_rate"] == 0.0
    assert dataset[2]["prior_labeled_count"] == 2
    assert dataset[2]["prior_missed_rate"] == 0.5


def test_csv_rejects_post_event_predictor_columns(tmp_path):
    with pytest.raises(ValueError, match="possible leakage"):
        load_rows(tmp_path, synthetic_rows(), extra_columns=["final_activity_status"])


def test_post_event_created_schedule_is_excluded_from_training(tmp_path):
    source = synthetic_rows(2)
    source[0]["schedule_created_at"] = source[0]["occurrence_start"]
    rows, _ = load_rows(tmp_path, source)

    dataset, report = prepare_dataset.prepare(rows, evidence_type="synthetic")

    assert len(dataset) == 1
    assert report["excluded_rows"]["schedule_created_at_or_after_occurrence"] == 1


def test_older_outcome_without_end_snapshot_is_excluded(tmp_path):
    source = synthetic_rows(2)
    source[0]["occurrence_end"] = ""
    rows, _ = load_rows(tmp_path, source)

    dataset, report = prepare_dataset.prepare(rows, evidence_type="synthetic")

    assert len(dataset) == 1
    assert report["excluded_rows"]["missing_occurrence_end"] == 1


def test_no_baseline_is_claimed_for_insufficient_rows(tmp_path):
    rows, _ = load_rows(tmp_path, synthetic_rows(1))

    _, report = prepare_dataset.prepare(rows, evidence_type="synthetic")

    assert report["baseline"]["status"] == "insufficient_data"


def test_command_accepts_database_stream_on_stdin_and_writes_only_derived_rows(tmp_path):
    source = synthetic_rows()
    input_stream = io.StringIO()
    writer = csv.DictWriter(input_stream, fieldnames=list(prepare_dataset.INPUT_COLUMNS))
    writer.writeheader()
    writer.writerows(source)
    output_path = tmp_path / "derived.csv"
    report_path = tmp_path / "report.json"

    result = subprocess.run(
        [
            sys.executable,
            str(MODULE_PATH),
            "--input",
            "-",
            "--output",
            str(output_path),
            "--report",
            str(report_path),
            "--evidence-type",
            "synthetic",
        ],
        input=input_stream.getvalue(),
        text=True,
        capture_output=True,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    assert json.loads(report_path.read_text(encoding="utf-8"))["evidence_type"] == "synthetic"
    with output_path.open(newline="", encoding="utf-8") as stream:
        derived = list(csv.DictReader(stream))
    assert len(derived) == 10
    assert "user_id" not in derived[0]
    assert "schedule_id" not in derived[0]

"""Prepare a private, leakage-safe occurrence dataset and majority baseline."""

from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import Counter
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any


INPUT_COLUMNS = {
    "schedule_id",
    "user_id",
    "occurrence_start",
    "occurrence_end",
    "schedule_created_at",
    "category",
    "priority",
    "outcome",
    "recorded_at",
}
LABELS = {"completed": 0, "missed": 1}
EXCLUDED_OUTCOMES = {"skipped", "cancelled"}
CATEGORIES = {"work", "exercise", "leisure", "errand", "social", "rest"}
PRIORITIES = {"low", "medium", "high"}
FEATURE_COLUMNS = [
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
OUTPUT_COLUMNS = ["scheduled_at", *FEATURE_COLUMNS, "label"]


@dataclass(frozen=True)
class OccurrenceRow:
    schedule_id: str
    user_id: str
    start: datetime
    end: datetime | None
    created_at: datetime
    category: str
    priority: str
    outcome: str
    recorded_at: datetime | None


def parse_datetime(value: str, field: str, row_number: int) -> datetime:
    try:
        parsed = datetime.fromisoformat(value)
    except (TypeError, ValueError) as error:
        raise ValueError(f"Row {row_number}: {field} must be an ISO-8601 timestamp") from error
    if parsed.tzinfo is not None:
        raise ValueError(f"Row {row_number}: {field} must use the same timezone-naive format")
    return parsed


def read_rows(input_path: Path | str) -> tuple[list[OccurrenceRow], Counter[str]]:
    rows: list[OccurrenceRow] = []
    exclusions: Counter[str] = Counter()
    seen: set[tuple[str, datetime]] = set()

    stream_context = sys.stdin if str(input_path) == "-" else Path(input_path).open(newline="", encoding="utf-8-sig")
    try:
        stream = stream_context
        reader = csv.DictReader(stream)
        if reader.fieldnames is None:
            raise ValueError("Input CSV is empty or has no header")

        missing = INPUT_COLUMNS - set(reader.fieldnames)
        unexpected = set(reader.fieldnames) - INPUT_COLUMNS
        if missing or unexpected:
            details = []
            if missing:
                details.append(f"missing columns: {', '.join(sorted(missing))}")
            if unexpected:
                details.append(f"unsupported columns (possible leakage): {', '.join(sorted(unexpected))}")
            raise ValueError("Input schema rejected; " + "; ".join(details))

        for row_number, data in enumerate(reader, start=2):
            start = parse_datetime(data["occurrence_start"], "occurrence_start", row_number)
            end = (
                parse_datetime(data["occurrence_end"], "occurrence_end", row_number)
                if data["occurrence_end"].strip()
                else None
            )
            created_at = parse_datetime(data["schedule_created_at"], "schedule_created_at", row_number)
            recorded_at = (
                parse_datetime(data["recorded_at"], "recorded_at", row_number)
                if data["recorded_at"].strip()
                else None
            )
            schedule_id = data["schedule_id"].strip()
            user_id = data["user_id"].strip()
            outcome = data["outcome"].strip().lower()
            if not schedule_id or not user_id:
                raise ValueError(f"Row {row_number}: schedule_id and user_id are required")
            if end is not None and end <= start:
                raise ValueError(f"Row {row_number}: occurrence_end must be after occurrence_start")
            category = data["category"].strip().lower()
            priority = data["priority"].strip().lower()
            if category not in CATEGORIES or priority not in PRIORITIES:
                raise ValueError(f"Row {row_number}: unsupported category or priority")
            key = (schedule_id, start)
            if key in seen:
                raise ValueError(f"Row {row_number}: duplicate schedule_id and occurrence_start")
            seen.add(key)

            if outcome in EXCLUDED_OUTCOMES:
                exclusions[outcome] += 1
            elif outcome not in LABELS and outcome:
                raise ValueError(f"Row {row_number}: unsupported outcome {outcome!r}")
            elif not outcome:
                exclusions["unreported"] += 1
            elif recorded_at is None:
                exclusions["missing_recorded_at"] += 1
            elif end is None:
                exclusions["missing_occurrence_end"] += 1
            elif recorded_at < end:
                exclusions["recorded_before_occurrence_end"] += 1

            rows.append(
                OccurrenceRow(
                    schedule_id=schedule_id,
                    user_id=user_id,
                    start=start,
                    end=end,
                    created_at=created_at,
                    category=category,
                    priority=priority,
                    outcome=outcome,
                    recorded_at=recorded_at,
                )
            )

    finally:
        if stream is not sys.stdin:
            stream.close()

    return rows, exclusions


def is_eligible(row: OccurrenceRow) -> bool:
    return (
        row.outcome in LABELS
        and row.created_at < row.start
        and row.end is not None
        and row.recorded_at is not None
        and row.recorded_at >= row.end
    )


def derive_features(row: OccurrenceRow, all_rows: list[OccurrenceRow]) -> dict[str, Any]:
    prior = [
        candidate
        for candidate in all_rows
        if candidate.user_id == row.user_id
        and candidate.start < row.start
        and candidate.end is not None
        and candidate.end <= row.start
        and candidate.outcome in LABELS
        and candidate.recorded_at is not None
        and candidate.recorded_at < row.start
    ]
    same_category = [candidate for candidate in prior if candidate.category == row.category]
    assert row.end is not None
    duration = (row.end - row.start).total_seconds() / 60
    lead_hours = (row.start - row.created_at).total_seconds() / 3600

    return {
        "category": row.category,
        "priority": row.priority,
        "weekday": row.start.weekday(),
        "scheduled_hour": row.start.hour,
        "duration_minutes": round(duration, 2),
        "lead_hours": round(lead_hours, 2),
        "prior_labeled_count": len(prior),
        "prior_missed_rate": _missed_rate(prior),
        "prior_same_category_labeled_count": len(same_category),
        "prior_same_category_missed_rate": _missed_rate(same_category),
    }


def _missed_rate(rows: list[OccurrenceRow]) -> float | None:
    if not rows:
        return None
    return round(sum(row.outcome == "missed" for row in rows) / len(rows), 6)


def prepare(
    rows: list[OccurrenceRow], evidence_type: str = "unspecified"
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    eligible = sorted((row for row in rows if is_eligible(row)), key=lambda row: row.start)
    dataset = []
    for row in eligible:
        dataset.append(
            {
                "scheduled_at": row.start.isoformat(sep=" "),
                **derive_features(row, rows),
                "label": LABELS[row.outcome],
            }
        )

    split_index = int(len(eligible) * 0.8)
    baseline: dict[str, Any] = {"status": "insufficient_data", "method": "training-period majority class"}
    if len(eligible) >= 2:
        split_at = eligible[split_index].start if split_index < len(eligible) else None
        if split_at is not None:
            train = [row for row in eligible if row.start < split_at]
            test = [row for row in eligible if row.start >= split_at]
            train_counts = Counter(LABELS[row.outcome] for row in train)
            test_counts = Counter(LABELS[row.outcome] for row in test)
            if train and test:
                majority = 1 if train_counts[1] > train_counts[0] else 0
                correct = sum(LABELS[row.outcome] == majority for row in test)
                baseline = {
                    "status": "computed",
                    "method": "training-period majority class",
                    "split": "chronological; test includes timestamps at or after split_at",
                    "split_at": split_at.isoformat(sep=" "),
                    "train_rows": len(train),
                    "test_rows": len(test),
                    "train_class_balance": {"completed": train_counts[0], "missed": train_counts[1]},
                    "test_class_balance": {"completed": test_counts[0], "missed": test_counts[1]},
                    "predicted_class": "missed" if majority else "completed",
                    "test_accuracy": round(correct / len(test), 6),
                }

    counts = Counter(row.outcome for row in eligible)
    report = {
        "evidence_type": evidence_type,
        "input_rows": len(rows),
        "eligible_labeled_rows": len(eligible),
        "eligible_users": len({row.user_id for row in eligible}),
        "class_balance": {"completed": counts["completed"], "missed": counts["missed"]},
        "excluded_rows": dict(sorted(_exclusion_counts(rows).items())),
        "missing_history_behavior": "prior counts are 0; history rates are null until an earlier eligible outcome exists",
        "unreported_outcome_count": None,
        "unreported_outcome_count_note": "The outcome table stores only explicit records; absence is not countable without reconstructing schedule history.",
        "unsupported_candidate_features": ["historical schedule density: the current schema has no schedule audit trail for reconstructing each past calendar"],
        "features": FEATURE_COLUMNS,
        "predictor_columns_exclude": ["schedule_id", "user_id", "outcome", "recorded_at", "schedule_created_at"],
        "baseline": baseline,
        "user_facing_predictions_enabled": False,
    }
    return dataset, report


def _exclusion_counts(rows: list[OccurrenceRow]) -> Counter[str]:
    counts: Counter[str] = Counter()
    for row in rows:
        if row.outcome in EXCLUDED_OUTCOMES:
            counts[row.outcome] += 1
        elif not row.outcome:
            counts["unreported"] += 1
        elif row.outcome in LABELS and row.recorded_at is None:
            counts["missing_recorded_at"] += 1
        elif row.outcome in LABELS and row.end is None:
            counts["missing_occurrence_end"] += 1
        elif row.outcome in LABELS and row.recorded_at is not None and row.end is not None and row.recorded_at < row.end:
            counts["recorded_before_occurrence_end"] += 1
        elif row.outcome in LABELS and row.created_at >= row.start:
            counts["schedule_created_at_or_after_occurrence"] += 1
    return counts


def write_dataset(path: Path, dataset: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=OUTPUT_COLUMNS)
        writer.writeheader()
        writer.writerows(dataset)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, help="Private occurrence CSV path, or '-' for stdin")
    parser.add_argument("--output", type=Path, required=True, help="Private derived feature CSV")
    parser.add_argument("--report", type=Path, required=True, help="Private JSON data and baseline report")
    parser.add_argument(
        "--evidence-type",
        choices=["synthetic", "approved-real", "unspecified"],
        default="unspecified",
        help="Label the provenance; real data requires prior institutional approval",
    )
    args = parser.parse_args()

    try:
        rows, _ = read_rows(args.input)
        dataset, report = prepare(rows, args.evidence_type)
        write_dataset(args.output, dataset)
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    except (OSError, ValueError) as error:
        parser.error(str(error))

    print(json.dumps(report, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

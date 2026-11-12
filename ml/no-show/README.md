# No-show preparation pipeline (Sprint 8)

This folder prepares explicit Wasaa schedule-occurrence outcomes for a future model evaluation. It does not train or serve a user-facing model. Prediction remains disabled until institutional approval, adequate approved data, and held-out evaluation are confirmed.

## Label and feature rules

- One explicit schedule occurrence is one row.
- `completed` is label 0 and `missed` is label 1. `skipped`, `cancelled`, missing outcomes, invalid label timestamps, and occurrences without a stored end-time snapshot are excluded. Malformed timestamp formats reject the input rather than being silently repaired.
- A label must have been recorded at or after the occurrence ended. A schedule must have existed before its occurrence.
- Predictors are category, priority, weekday, scheduled hour, duration, lead time, and counts/rates from the same user's earlier explicit `completed`/`missed` occurrences. An earlier outcome contributes only if its occurrence ended and its result was recorded before the target occurrence began.
- No title, description, user ID, schedule ID, final outcome, or record timestamp is a predictor. Unknown input columns are rejected to prevent accidental leakage.
- Missing personal history is represented by count 0 and a null rate; no arbitrary history threshold is applied.
- Historical schedule density is not included because the current schema does not preserve enough schedule-edit history to reconstruct past calendars safely.
- The baseline predicts the training period's majority class and is scored on the latest chronological holdout. Tied timestamps remain together in the holdout. No model is fitted.

## Preparing an approved local dataset

The Laravel command streams needed occurrence records from the configured database directly into the Python process through memory. It does not create a raw CSV export. The derived feature file and JSON report are written to `web/storage/app/private/no-show/`, which is ignored by Git.

Only run this command after required supervisor/institutional approval is confirmed:

```powershell
cd web
php artisan noshow:prepare-dataset --approved-real-data --python=python
```

`--approved-real-data` is an explicit confirmation gate. On Linux, use `--python=python3` if that is the installed executable. The report records data size, class balance, distinct users represented, exclusions, missing-history behavior and the baseline. The source table contains only explicit outcomes, so the number of unreported historical occurrences cannot be reliably counted from the current schema. Deleted or edited schedule history cannot be reconstructed either.

The preparation command deliberately does not enable predictions. If no eligible real outcomes exist, it reports that the baseline cannot yet be computed. Never commit the generated files or copy raw user records into a fixture.

## Synthetic tests

The unit tests use generated synthetic rows and verify label rules, prior-history timing, leakage rejection, chronological baseline calculation and insufficient-data reporting. These results test the pipeline only; they are not evidence of Wasaa-user model performance.

```powershell
cd ml/slot-recommender
.\.venv\Scripts\Activate.ps1
python -m pytest ..\no-show\tests
```

## Current evidence status

The repository has no approved real dataset snapshot. This issue can verify the pipeline with synthetic records, but a real dataset-size summary and real-data baseline remain unavailable until approved outcomes are collected in Wasaa.

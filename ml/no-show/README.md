# No-show preparation pipeline (Sprint 8)

This folder contains the leakage-controlled dataset preparation and research-only model evaluation pipeline for Wasaa commitment-risk estimates. The service integration and optional in-app alert path are implemented elsewhere in the repository, but remain disabled until an approved-real model artifact, adequate approved data, and reviewed held-out evaluation are available. The external [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling) repository is a workflow reference only; its synthetic rows and booking-specific features are not imported into Wasaa.

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

## Model evaluation gate

`src/train_model.py` adapts the logistic-regression/calibration workflow from [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling) to the prepared Wasaa feature schema. The upstream synthetic rows and booking-specific fields are not used. The script selects regularization and a candidate F1 threshold on the validation period, fits Platt calibration on validation logits, and evaluates once on the same held-out cutoff used by the baseline. It compares held-out accuracy and Brier score with a majority-class baseline and writes a model card with provenance, counts, split, metrics, date, and commit.

The trainer refuses to create artifacts unless the dataset report is labeled `approved-real` and the baseline has a valid chronological holdout. It also stops if the chronological train, validation, or test partition cannot support both labels. Synthetic fixtures may exercise the training and inference code in tests, but their metrics are not Wasaa-user evidence. All artifacts and reports belong in private local storage and stay out of Git. Even a successful evaluation leaves `user_facing_predictions_enabled` false; data sufficiency, metric review, and product alert thresholds still need approval.

## Service and alert integration

The Python service exposes a separate private `POST /noshow/predict` route; it does not replace the slot recommender's `POST /predict` route. Laravel performs authentication, schedule ownership and occurrence eligibility checks, derives the approved feature fields from the user's earlier explicit outcomes, and calls the Python service only when `COMMITMENT_RISK_ENABLED=true`. The request contains category, priority, weekday, scheduled hour, duration, lead time, and prior labeled-outcome counts/rates. It contains no user or schedule identifiers, titles, descriptions, notes, or raw outcome history. The model service does not connect to the application database.

The optional Laravel alert command is scheduled once per minute. It requires all of: an enabled Laravel feature flag, a numeric alert threshold from 0 through 1, the user's opt-in preference, an eligible future occurrence in the 59–61 minute window, and a successful model assessment meeting the threshold. Alerts are in-app only, are deduplicated per assessment, and can be dismissed. No email or push delivery or automatic schedule change is performed. Alternative times use the existing recommendation and conflict-check flow and require explicit user confirmation. Keep both risk flags off and the threshold unset until the model readiness gate is approved. See [the system architecture](../../docs/system-architecture.md), [end-to-end checks](../../docs/commitment-risk-e2e-checks.md), and [Azure deployment runbook](../../docs/azure-vm-deployment.md) for request flow and operations.

## Current evidence record

| Evidence item | Repository status |
|---|---|
| Approved real Wasaa occurrence dataset and dataset counts | Not available in this checkout; no real dataset was prepared or read for the Sprint 8 implementation. |
| Real-data majority baseline and held-out model metrics | Not available; no approved-real training report or model card is present. |
| Real-data evaluation date and evaluated commit | Not available; there is no real-data evaluation to identify. |
| Tracked configuration defaults | Laravel and Python risk flags are false and the alert threshold is unset. A private VM override cannot be verified from this repository checkout. |
| Automated pipeline / endpoint tests | Synthetic generated rows and test doubles verify code behavior only. They do not measure Wasaa-user performance. |
| Azure deployment verification | Deferred until the user completes the planned final hosting pass; repository documentation does not claim VM verification. |

Do not replace these statuses with illustrative values. After institutional/supervisor approval, prepare a private dataset, review its report and exclusions, evaluate a model, record the resulting private model card's counts/split/metrics/date/commit, review threshold and alert-volume tradeoffs, and complete deployment checks before enabling predictions. Do not add raw user records, private feature files, model artifacts, or sensitive reports to Git.

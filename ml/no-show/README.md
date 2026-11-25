# Archived no-show research

The running WasaaLifestyle app has no no-show model, activity-risk rules, or risk reminders. This folder contains historical experiments and synthetic appointment-domain training material only; Laravel and Flask do not load or call it.

The remaining preparation and training notes below describe archived research scripts, not supported app workflows. In particular, the Laravel `noshow:prepare-dataset` command was removed and cannot be run in the current app.

The archived experiments do not describe the current app. The live ML feature is the separate slot recommender in `../slot-recommender/`.

The slot recommender can use a consenting user's own activity outcomes to personalize rankings. It does not train a shared model on user data.

## Label and feature rules

- One explicit schedule occurrence is one row.
- `completed` is label 0 and `missed` is label 1. `skipped`, `cancelled`, missing outcomes, invalid label timestamps, and occurrences without a stored end-time snapshot are excluded. Malformed timestamp formats reject the input rather than being silently repaired.
- A label must have been recorded at or after the occurrence ended. A schedule must have existed before its occurrence.
- Predictors are category, priority, weekday, scheduled hour, duration, lead time, and counts/rates from the same user's earlier explicit `completed`/`missed` occurrences. An earlier outcome contributes only if its occurrence ended and its result was recorded before the target occurrence began.
- No title, description, user ID, schedule ID, final outcome, or record timestamp is a predictor. Unknown input columns are rejected to prevent accidental leakage.
- Missing personal history is represented by count 0 and a null rate; no arbitrary history threshold is applied.
- Schedule time changes are recorded separately as reschedule events, including old/new times and whether the change affects one activity, one recurring occurrence, or the whole recurring series. They are not labeled completed or missed.
- Historical schedule density is not included because the current schema does not preserve enough schedule-edit history to reconstruct past calendars safely.
- The baseline predicts the training period's majority class and is scored on the latest chronological holdout. Tied timestamps remain together in the holdout. No model is fitted.

## Preparing an approved local dataset

Historical implementation notes below are retained as research history only. The Laravel dataset command and app integration have been removed; these instructions do not describe the current application or deployment process.

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

## Train a model from Wasaa outcomes

The training scripts and synthetic appointment data below are archived research material. They are not part of the running app, and the app does not train a model. The Wasaa preparation/training commands are retained only for possible future research if that work is approved.

The historical rule assessment added points for duration, schedule load, overlaps, time of day, and prior missed outcomes. It is not used by the running app.

After required institutional/supervisor approval, prepare the private dataset using the Laravel command above, then train from those derived files:

```powershell
cd ml/slot-recommender
.\.venv\Scripts\python.exe ..\no-show\src\train_model.py `
  --dataset ..\..\web\storage\app\private\no-show\occurrence-features.csv `
  --report ..\..\web\storage\app\private\no-show\baseline-report.json `
  --artifact ..\no-show\models\commitment_risk.joblib `
  --model-card ..\no-show\models\model_card.json
```

A successful research run still writes `user_facing_predictions_enabled: false`. Keep the model unavailable until data sufficiency, held-out performance, calibration, alert burden, and a user-facing threshold have been reviewed and the model card is explicitly approved. Never commit raw occurrence data or generated model artifacts.

## Current Wasaa evidence status

There is no active no-show model or activity-risk rule system in the application. No predictive accuracy claim is made.

## Model evaluation gate

Any future decision to add no-show prediction is outside the current implementation and would require suitable Wasaa outcomes and a separate evaluation.

The trainer refuses to create artifacts unless the dataset report is labeled `approved-real` and the baseline has a valid chronological holdout. It also stops if the chronological train, validation, or test partition cannot support both labels. Synthetic fixtures may exercise this pipeline, but their metrics are not Wasaa-user evidence. All model artifacts and private reports stay out of Git. Even a successful evaluation leaves `user_facing_predictions_enabled` false pending review of data sufficiency, metrics, and an alert threshold.

## Service and alert integration

The running app has no no-show risk service or reminder scheduler. Flask handles slot recommendations only. See [the system architecture](../../docs/system-architecture.md) and [Azure deployment runbook](../../docs/azure-vm-deployment.md).

## Current evidence record

| Evidence item | Repository status |
|---|---|
| Active no-show/risk feature | None; no no-show model, rules, or reminders are used by the app. |
| Previous synthetic appointment experiment | Archived research only; its data and metrics are not used by the app. |
| Approved real Wasaa occurrence dataset and dataset counts | Not available; no real Wasaa dataset was prepared or read. |
| Real-data majority baseline and held-out model metrics | Not available; no approved-real training report or model card is present. |
| Real-data evaluation date and evaluated commit | Not available; there is no real-data evaluation to identify. |
| Runtime integration | None; the archived model and training scripts are disconnected from Laravel and Flask. |
| Automated pipeline / endpoint tests | Synthetic generated rows and test doubles verify code behavior only. They do not measure Wasaa-user performance. |
| Azure deployment verification | The GitHub Actions deployment to the VM completed successfully on 2026-11-25; this does not evaluate model performance. |

After institutional/supervisor approval, collect explicit Wasaa outcomes, prepare a private dataset, review exclusions, evaluate against a chronological holdout and baseline, and enable a model only if the evidence supports it. Raw user records, private feature files, model artifacts, and sensitive reports stay out of Git.

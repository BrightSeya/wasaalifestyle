# No-show preparation pipeline (Sprint 8)

This folder supports two model stages. The cold-start prototype is trained from the pinned synthetic booking dataset in [`data/upstream/bookings.csv`](data/upstream/bookings.csv), using the Logistic Regression and Platt-calibration implementation from [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling/blob/main/app/model.py). It maps compatible request fields from Wasaa and uses training-set centers for source fields that Wasaa does not collect; unmatched lifestyle categories have no learned source-category effect. Prototype scores are labelled synthetic and do not establish prediction quality for Wasaa users. See [upstream provenance and feature mapping](UPSTREAM_MODEL.md).

With user consent, each assessment uses that user's prior completed/missed activity logs and schedule outcomes. These update overall and same-category history features, and same-category history progressively adjusts the synthetic cold-start probability with shrinkage. Skipped and cancelled outcomes are excluded. This per-user update happens as outcomes are recorded; it does not pool a person's data into another user's predictions or refit shared weights after every outcome. The separate Wasaa trainer can replace the cold-start model only after sufficient approved outcomes support chronological validation against a baseline.

The Python model service and Laravel integration are available by default. New users see an unchecked consent option at signup; accepting enables predictions and in-app alerts. Existing accounts stay opted out until enabled in Settings. Turning off **No-show predictions and learning** prevents future assessments and alert generation for that account. Estimates remain clearly labelled prototypes until evaluated with Wasaa outcomes.

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

## Cold-start model: train from the upstream synthetic dataset

The upstream repository does not commit a portable model artifact; its `train()` function fits one from its synthetic dataset. Wasaa bundles that dataset at the pinned upstream commit and reproduces the source chronological split, Logistic Regression, validation Platt calibration, and F1 threshold selection. Training also records a final chronological synthetic holdout in a local model card. These synthetic metrics describe only the source dataset.

From the repository root, create the private local artifact and card:

```powershell
cd ml/slot-recommender
.\.venv\Scripts\python.exe ..\no-show\src\upstream_cold_start.py `
  --artifact ..\no-show\models\commitment_risk.joblib `
  --model-card ..\no-show\models\model_card.json
```

The generated model files are ignored by Git. To load this model for a local prototype session, explicitly set both Python flags before starting Flask:

```powershell
$env:WASAA_RISK_MODEL_ENABLED = 'true'
$env:WASAA_RISK_ALLOW_SYNTHETIC_MODEL = 'true'
python app/api.py
```

Laravel's service integration and alert threshold are enabled by default, but predictions run only for a user who opted in at signup or in Settings. The threshold selected on the upstream synthetic validation slice is not a real-user alert threshold; the configured `0.75` default is conservative but also requires Wasaa evaluation. Every estimate and alert is labelled as prototype-only.

## Current Wasaa evidence status

The repository has the pinned upstream synthetic dataset and no approved real Wasaa dataset snapshot. A cold-start artifact can be generated for a labelled prototype. Real Wasaa counts, baseline, and model metrics remain unavailable until approved Wasaa occurrence outcomes are collected.

## Model evaluation gate

`src/train_model.py` is the later Wasaa-data trainer. It uses the Wasaa outcome schema, pre-event features, chronological train/validation/held-out split, validation-fitted Platt calibration, and a majority baseline. It refuses persistent artifact creation unless the preparation report is `approved-real`. The cold-start model uses the source dataset; this later trainer uses approved Wasaa outcomes and replaces it only after evaluation review. The two kinds of metrics must not be compared as if they came from the same population or label definition.

The trainer refuses to create artifacts unless the dataset report is labeled `approved-real` and the baseline has a valid chronological holdout. It also stops if each chronological partition cannot support both labels. Synthetic fixtures may exercise this pipeline, but their metrics are not Wasaa-user evidence. All model artifacts and private reports stay out of Git. Even a successful Wasaa evaluation leaves `user_facing_predictions_enabled` false pending review of data sufficiency, metrics, and an alert threshold.

## Service and alert integration

The Python service exposes a separate private `POST /noshow/predict` route; it does not replace the slot recommender's `POST /predict` route. Laravel performs authentication, schedule ownership and occurrence eligibility checks, derives allow-listed features from earlier explicit outcomes, and can send zero/null history to the cold-start model. The Python service maps supported schedule/history features and does not connect to the application database. The synthetic upstream model is enabled in the tracked service configuration. Its API response is labelled `synthetic-upstream` and `prototype_only=true`; Laravel's per-user consent remains the final gate.

The Laravel alert command is scheduled once per minute. It requires the user's prediction opt-in and alert preference, an eligible upcoming occurrence, and a successful assessment above the configured threshold. Synthetic cold-start notifications are explicitly labelled “Prototype estimate” and explain that the model uses synthetic bookings. Alerts are in-app only, deduplicated per assessment, and dismissible; they never move a schedule automatically. Each account must explicitly opt in and all estimates remain clearly labelled prototypes. See [the system architecture](../../docs/system-architecture.md), [end-to-end checks](../../docs/commitment-risk-e2e-checks.md), and [Azure deployment runbook](../../docs/azure-vm-deployment.md).

## Current evidence record

| Evidence item | Repository status |
|---|---|
| Upstream synthetic data and training | 21,333 synthetic bookings, pinned to upstream commit `c40599197b054a5248461c0ff9a2532fd3e4da1c`; cold-start artifact generated locally and ignored by Git. |
| Synthetic upstream held-out result | ROC-AUC 0.7507 and Brier score 0.1358 on the upstream synthetic holdout; not Wasaa-user performance. |
| Approved real Wasaa occurrence dataset and dataset counts | Not available; no real Wasaa dataset was prepared or read. |
| Real-data majority baseline and held-out model metrics | Not available; no approved-real training report or model card is present. |
| Real-data evaluation date and evaluated commit | Not available; there is no real-data evaluation to identify. |
| Tracked configuration defaults | The synthetic cold-start service is available; each account must explicitly opt in. A private VM override cannot be verified from this repository checkout. |
| Automated pipeline / endpoint tests | Synthetic generated rows and test doubles verify code behavior only. They do not measure Wasaa-user performance. |
| Azure deployment verification | Deferred until the user completes the planned final hosting pass; repository documentation does not claim VM verification. |

Do not describe the synthetic holdout as Wasaa accuracy. After institutional/supervisor approval, collect explicit Wasaa outcomes, prepare a private dataset, review exclusions, evaluate against a chronological holdout and baseline, and replace the prototype only if the evidence supports it. Raw user records, private feature files, model artifacts, and sensitive reports stay out of Git.

# Slot Recommender

Random Forest service that predicts how suitable each hour of the day is for an activity
category and returns the top-3 time slots. Developed with CRISP-DM.

## Structure

```
ml/slot-recommender/
├── app/         Flask API (/health, /predict, /retrain)
├── src/         data loading, feature engineering, training, evaluation
├── tests/       pytest tests
├── notebooks/   exploratory data analysis
├── docs/        CRISP-DM phase write-ups
├── data/
│   ├── raw/        ATUS source files (not committed)
│   └── processed/  generated datasets (not committed)
└── models/      trained model and reports (.joblib not committed)
```

## Setup (Windows PowerShell, Python 3.11)

```powershell
cd ml/slot-recommender
py -3.11 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
pip install -r requirements-dev.txt
```

- `requirements.txt`: packages needed to train and serve the model.
- `requirements-dev.txt`: adds testing and notebook tools.

## Data

The model is trained on the **American Time Use Survey (ATUS) 2023** from the U.S. Bureau of Labor Statistics.

1. Open the ATUS 2023 data files page: https://www.bls.gov/tus/data/datafiles-2023.htm
2. Download the **ATUS 2023 Activity file** and the **ATUS 2023 Respondent file** (zip).
3. Extract and place these files in `data/raw/`:
   - `atusact_2023.dat`
   - `atusresp_2023.dat`

Raw and processed data are ignored by git; every derived file can be regenerated from these two.

## Tests

```powershell
pytest
```

## Running the API

Requires a trained model (`python src/train.py`, see the modelling docs) and the completion-rate
lookup (`python src/features.py` or `python src/negatives.py`).

```powershell
python app/api.py
```

Runs on http://127.0.0.1:5001. Check it started correctly:

```powershell
curl http://127.0.0.1:5001/health
```

### Retraining the model

Set the shared secret as `ML_RETRAIN_KEY` in the service environment. Send the same value
in the `X-API-Key` header when calling `POST /retrain`; an unset, missing, or incorrect
key is rejected. Training errors return a clear `503` response and leave the currently
loaded model active.

```powershell
$headers = @{ "X-API-Key" = $env:ML_RETRAIN_KEY }
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:5001/retrain -Headers $headers
```

On success, the endpoint reruns the training pipeline, reloads the model and completion
lookup into the running process, and returns `cv_f1` and `best_params` from the model card.

### Private commitment-risk endpoint

`POST /noshow/predict` is separate from the existing slot-recommendation `POST /predict` contract. It accepts only the approved derived feature object (category, priority, weekday, scheduled hour, duration, lead time, and prior recorded-outcome counts/rates). It accepts no user ID, schedule ID, titles, notes, or prototype booking ID.

The endpoint is disabled by default in both Flask and the Azure systemd unit. Enable it only after an approved-real model artifact and matching model card have been installed in the private `ml/no-show/models/` directory. Use a systemd override rather than editing the tracked unit:

```text
WASAA_RISK_MODEL_ENABLED=true
WASAA_RISK_MODEL_PATH=/var/www/wasaa/ml/no-show/models/commitment_risk.joblib
WASAA_RISK_MODEL_CARD_PATH=/var/www/wasaa/ml/no-show/models/model_card.json
```

Missing, disabled, incompatible, version-mismatched, or synthetic-only artifacts return HTTP 503 without affecting the existing recommender. The current Azure systemd unit binds Gunicorn to `127.0.0.1:5001`; keep port 5001 closed in the VM network security group.

Request example (illustrative values only):

```json
{
  "category": "work",
  "priority": "medium",
  "weekday": 2,
  "scheduled_hour": 14,
  "duration_minutes": 60,
  "lead_hours": 24,
  "prior_labeled_count": 0,
  "prior_missed_rate": null,
  "prior_same_category_labeled_count": 0,
  "prior_same_category_missed_rate": null
}
```

The response contains a probability in `[0, 1]`, up to three positive logistic-contribution factors, and model name/version metadata. Invalid, missing, extra, or out-of-range fields receive HTTP 400. Service errors and unavailable models do not echo submitted values or identifiers. The endpoint itself does not create alerts or enable user-facing predictions; Laravel integration and alert controls are separate Sprint 8 issues.


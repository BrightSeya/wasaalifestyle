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

On success, the endpoint reruns the training pipeline, reloads the model, completion lookup,
and validation-selected suitability threshold into the running process, and returns `cv_f1`,
`best_params`, and `decision_threshold` from the model card.

### Learning from Wasaa outcomes

When the user opts in during registration or in Settings, each recommendation request
includes compact outcome counts for that user's weekday and hour, derived from the previous
365 days of activity logs and schedule occurrence outcomes. Completed outcomes increase the
personal suitability estimate; missed outcomes decrease it. Skipped and cancelled outcomes
are omitted because they do not show that a time slot was unsuitable. Feedback decays with
a 180-day half-life, and hierarchical shrinkage keeps sparse personal history close to the
ATUS-trained cold-start model. The counts are sent only for the signed-in user and are not
pooled across users. If the user declines or later turns personalization off, no outcome
history is sent and standard ATUS-based recommendations remain available.

### Evaluation threshold

Training selects the suitability probability threshold that maximizes F1 on the respondent-
held-out validation split. That threshold is stored in the model card and used by `/predict`
for the `suitable` flag. The untouched test split is then scored once at that selected
threshold; its metrics are recorded separately in the model card. Hit Rate@3 and MAE evaluate
ranking and do not depend on the binary classification threshold.

This updates that user's ranking immediately without refitting the Random Forest after
every event. `POST /retrain` still retrains the population model from ATUS files only; it
does not use Wasaa outcomes. This separation avoids overfitting tiny, noisy histories and
keeps one user's schedule data from changing recommendations for everyone else.

### User scheduling preferences

Laravel sends the requested weekday's enabled availability hours and the user's preferred
productivity-hour range with each recommendation request. The ranker excludes hours outside
availability, keeps only start hours where the full requested duration ends by the
selected window's closing time, ranks available hours inside the preferred range first, and then fills any
remaining top-three slots from other available hours. The model confidence remains its
original ATUS/user-history score. Settings periods map to morning 06:00–11:00, afternoon
12:00–17:00, and evening 18:00–23:00; hours 00:00–05:00 are outside the selectable windows.
If no periods are enabled for that weekday, the API returns a clear error asking the user
to update availability.

### No-show risk

Wasaa does not include a no-show/activity-risk prediction or reminder feature. Flask exposes the time-slot recommendation contract at `POST /predict` and the protected ATUS retraining endpoint.


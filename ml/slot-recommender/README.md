# Slot Recommender

The population Random Forest predicts suitable hours for an activity category. For users
who opt in and have enough outcomes, the service can use a separate personal model when it
beats the population model on recent held-out outcomes. Developed with CRISP-DM.

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

### Personal models from Wasaa outcomes

When a user opts in during registration or in Settings, Laravel sends only that user's
completed and missed activity-log and schedule-occurrence outcomes from the previous 365
days. Skipped and cancelled outcomes are excluded. Each example includes its category,
local weekday and hour, duration, observed schedule density, gap since the prior outcome in
that category, result, and a recency weight with a 180-day half-life. The service caps the
history at the 2,000 most recent examples. Personal training uses category, weekday, hour,
duration, and the ATUS completion-rate prior; weekday and hour are encoded as cyclic values.
The extra context fields let the population model be compared on the same personal outcomes.

The service needs at least 120 outcomes, including at least 20 completed and 20 missed, to
try a separate model for that user. It trains on earlier outcomes and holds out the most
recent 20% (at least 30 outcomes). The held-out set must contain at least five outcomes of
each type. Flask compares the personal model's recency-weighted Brier score with the
population model on those same held-out outcomes and uses the personal model only when its
score is lower. Its suitability cutoff is also selected from that user's held-out outcomes;
the ATUS model's cutoff is not reused for personal scores. The selected model is fit on all
of that user's examples for the current request; no per-user model or raw history is saved
by Flask or shared with another user's request. If there is not enough history or the
personal model does not beat the population model, Wasaa uses the population model plus its
existing recency-weighted personal adjustment.

Turning personalization off means no personal history is sent. The population model remains
available. The separate `POST /retrain` endpoint continues to train only the population
model from ATUS data.

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


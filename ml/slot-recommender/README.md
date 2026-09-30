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


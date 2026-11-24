# CRISP-DM Phase 4 — Modelling

## 1. Setup

- **Model:** `RandomForestClassifier` (scikit-learn), `random_state=42`.
- **Hyperparameter search:** `GridSearchCV`, scored on F1, over:
  - `n_estimators`: 100, 200, 300
  - `max_depth`: 10, 20, None
  - `min_samples_leaf`: 1, 5
- **Cross-validation:** 5-fold **`GroupKFold` on `tucaseid`** — every fold contains a distinct set of
  respondents, so no person's rows are split between a search's training and validation folds. This is
  the same fix applied to the main train/val/test split in #22, applied one level down inside the search
  itself (the prototype's plain 5-fold CV did not have this guarantee).
- **Training data:** `data/processed/train.csv` (#24), 12 features (§3.2.4), balanced ~50/50 label split.
- **Evaluation:** each model's F1 cutoff is selected on the respondent-held-out validation set.
  Ranking metrics are measured on validation as before. The Random Forest cutoff is applied once
  to the untouched held-out test split for the final evaluation.

## 2. Grid search result

Two grid searches were run, the second a CRISP-DM iteration after the first fell short of the F1 target.

| | Iteration 1 | Iteration 2 |
|---|---|---|
| Grid | `n_estimators` 100/200/300, `max_depth` 10/20/None, `min_samples_leaf` 1/5 | `n_estimators` 300/400/500, `max_depth` 15/20/25, `min_samples_leaf` 2/5 (re-centred on iteration 1's result) |
| Best parameters | `n_estimators=300, max_depth=20, min_samples_leaf=5` | `n_estimators=300, max_depth=15, min_samples_leaf=2` |
| Best CV F1 | 0.7284 | 0.7299 |
| Training time | 7.8 min | 14.7 min |

**Both edges moved between iterations** (`max_depth` from the top option to the bottom, `min_samples_leaf`
from the top to the bottom), while CV F1 barely changed (0.7284 -> 0.7299). This suggests a flat search
surface for the tested model settings. The final classifier is retained; selecting a probability cutoff on
validation improves binary F1 without changing the model's ranking of candidate hours.

## 3. Comparison with the baselines (validation set)

| Model | Validation F1 (threshold) | Hit Rate@3 | MAE (hours) |
|---|---|---|---|
| Completion rate only (no training) | 0.692 (0.2684) | 0.276 | 4.66 |
| Logistic regression | 0.699 (0.3948) | 0.270 | 4.71 |
| **Random Forest** | **0.744 (0.3823)** | **0.361** | **3.68** |

The Random Forest outperforms both baselines on validation F1, Hit Rate@3 and MAE. The reported F1
values use a validation-selected threshold for each model; thresholds appear in parentheses. Ranking
metrics do not depend on the threshold. Relative to the completion-rate baseline, Random Forest
Hit Rate@3 improves by about 31% and MAE falls by about 21%.

## 4. Success criteria

| Criterion | Target | Result |
|---|---|---|
| F1-score | > 0.75 on held-out test | Validation: 0.744; held-out test: **0.751 — met narrowly** |
| Hit Rate@3 | reported | Validation: 0.361; test: 0.367 |
| MAE | reported | Validation: 3.68 hours; test: 3.60 hours |

Validation F1 remains below 0.75; the untouched held-out test F1 is 0.751 at the cutoff selected on
validation (0.3823). This meets the proposal's test-set target by a narrow margin. Do not use the test
result to select another threshold or tune the model.

- **Data Preparation (#21-#24) already changed the problem**, on purpose. Removing travel (43% of the
  old errand category) and the artificial 04:00 diary-start rows produced more realistic, but *harder*,
  labels than the prototype's uncleaned 0.758 F1. The prototype's row-level split and un-grouped CV also
  likely inflated its own reported F1 through leakage (see Part B of the project log, D8-D11), so the
  two numbers are not directly comparable.
- **Modelling**: selected parameters remain `n_estimators=300`, `max_depth=15`, and
  `min_samples_leaf=2`. Threshold selection moves validation F1 from 0.725 at 0.5 to 0.744 at 0.3823;
  the held-out test F1 is 0.751.
- User outcomes adjust per-user recommendation scores when personalization is enabled. The `/retrain`
  endpoint continues to train from ATUS data only.

## 5. Feature importances

| Feature | Importance |
|---|---|
| historical_completion_rate | 0.2939 |
| hour | 0.1813 |
| gap_since_last_same_category_hrs | 0.1671 |
| duration_min | 0.1477 |
| schedule_density | 0.0776 |
| category_rest | 0.0554 |
| day_of_week | 0.0387 |
| category_leisure | 0.0117 |
| category_errand | 0.0103 |
| category_work | 0.0077 |
| category_social | 0.0069 |
| category_exercise | 0.0017 |

The two engineered features fixed in Data Preparation, `historical_completion_rate` (D12) and
`gap_since_last_same_category_hrs`, are the two strongest predictors, together worth more than the six
category columns combined. This supports treating the Data Preparation fixes as a meaningful
contribution, not just cleanup.

## 6. Deployment artefacts

- `models/rf_model.joblib` — the trained model (not committed; regenerate with `python src/train.py`).
- `models/model_card.json` — hyperparameters, CV F1, training size, feature list/importances, selected
  threshold, and separate validation/test metrics (committed).
- `models/baselines.json` — all three models' validation metrics side by side (committed).

## 7. Limitations carried from Data Preparation

- Labels are a proxy: "not suitable" means the person was doing something else then, not that they
  would reject the slot.
- ATUS covers one diary day per respondent; the gap feature only sees within-day patterns.
- Trained on a U.S. population; in-app data (`activity_logs`) is intended to personalise the model
  through retraining as real Wasaa users generate history.

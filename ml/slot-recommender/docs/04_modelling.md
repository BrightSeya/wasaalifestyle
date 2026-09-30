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
- **Evaluation:** validation set, scored with the exact same functions used for the baselines
  (`metrics.precision_recall_f1`, `metrics.hit_rate_at_k`, `metrics.mean_absolute_error_hours`), so all
  three models are compared on equal terms.

## 2. Grid search result

Two grid searches were run, the second a CRISP-DM iteration after the first fell short of the F1 target.

| | Iteration 1 | Iteration 2 |
|---|---|---|
| Grid | `n_estimators` 100/200/300, `max_depth` 10/20/None, `min_samples_leaf` 1/5 | `n_estimators` 300/400/500, `max_depth` 15/20/25, `min_samples_leaf` 2/5 (re-centred on iteration 1's result) |
| Best parameters | `n_estimators=300, max_depth=20, min_samples_leaf=5` | `n_estimators=300, max_depth=15, min_samples_leaf=2` |
| Best CV F1 | 0.7284 | 0.7299 |
| Training time | 7.8 min | 14.7 min |

**Both edges moved between iterations** (`max_depth` from the top option to the bottom, `min_samples_leaf`
from the top to the bottom), while CV F1 barely changed (0.7284 -> 0.7299). This is the signature of a flat
search surface, not a real trend: the model has reached a **performance ceiling for these features**, and
further hyperparameter tuning is unlikely to close the gap to 0.75. Closing it further would need new or
different features (Data Preparation), which is out of scope for the time available. The iteration-2 model
is kept as the final one, since it is at least as good as iteration 1 on every metric.

## 3. Comparison with the baselines (validation set)

| Model | F1 | Hit Rate@3 | MAE (hours) |
|---|---|---|---|
| Completion rate only (no training) | 0.649 | 0.276 | 4.66 |
| Logistic regression | 0.635 | 0.270 | 4.71 |
| **Random Forest** | **0.725** | **0.361** | **3.68** |

The Random Forest clearly outperforms both baselines: Hit Rate@3 improves by 31% relative to the
completion-rate baseline (0.276 -> 0.362), and MAE falls by 21% (4.66h -> 3.67h). This is the gain a
non-linear model can extract once it combines completion rate with the gap, hour, duration and density
features together, which the linear baseline could not do.

## 4. Success criteria

| Criterion | Target | Result |
|---|---|---|
| F1-score | > 0.75 | 0.725 — **not met** (2.5 points short) |
| Hit Rate@3 | reported | 0.361 |
| MAE | reported | 3.68 hours |

The F1 target was not reached. Per CRISP-DM, this calls for revisiting Data Preparation or Modelling
before accepting the result:

- **Data Preparation (#21-#24) already changed the problem**, on purpose. Removing travel (43% of the
  old errand category) and the artificial 04:00 diary-start rows produced more realistic, but *harder*,
  labels than the prototype's uncleaned 0.758 F1. The prototype's row-level split and un-grouped CV also
  likely inflated its own reported F1 through leakage (see Part B of the project log, D8-D11), so the
  two numbers are not directly comparable.
- **Modelling**: `n_estimators` reached the top of its grid (300), so a wider grid (e.g. up to 500) might
  close some of the gap, at the cost of a longer search. Given the time available for this project, the
  current result is accepted as the delivered model, with this trade-off documented rather than hidden.
- The Random Forest still convincingly beats both baselines on every metric (Section 3), and the
  `/retrain` endpoint (Sprint 4-5) is designed to let the model keep improving as real Wasaa users
  generate `activity_logs`, rather than relying on ATUS alone.

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
- `models/model_card.json` — hyperparameters, CV F1, training size, feature list and importances (committed).
- `models/baselines.json` — all three models' validation metrics side by side (committed).

## 7. Limitations carried from Data Preparation

- Labels are a proxy: "not suitable" means the person was doing something else then, not that they
  would reject the slot.
- ATUS covers one diary day per respondent; the gap feature only sees within-day patterns.
- Trained on a U.S. population; in-app data (`activity_logs`) is intended to personalise the model
  through retraining as real Wasaa users generate history.

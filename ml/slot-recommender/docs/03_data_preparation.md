# CRISP-DM Phase 3 — Data Preparation

Pipeline (run from `ml/slot-recommender` with the venv active):

```powershell
python src/load_atus.py    # clean                -> data/processed/atus_clean.csv
python src/split.py        # respondent split     -> data/processed/split_assignment.csv
python src/features.py     # positives + lookup   -> data/processed/{split}_positives.csv
python src/negatives.py    # final datasets       -> data/processed/{train,val,test}.csv
```

`negatives.py` runs the whole chain from `atus_clean.csv`; the middle two scripts write intermediate files for inspection.

## 1. Cleaning (`src/load_atus.py`)
| Step | Rows removed | Rows left | Reason |
|---|---|---|---|
| Raw activity file | — | 153,120 | |
| Unmapped (data code `50`) | 1,600 | 151,520 | No Wasaa category |
| Travel (tier-1 `18`) | 25,041 | 126,479 | Links activities; not something users schedule. Was 43% of errand |
| Diary-start rows | 8,503 | **117,976** | First activity of the diary starting at exactly 04:00; the time is set by the survey, not chosen |

No nulls, negative codes, duplicates or zero durations were found (see `02_data_understanding.md`), so no further cleaning was needed.

Derived columns: `hour` (0–23), `day_of_week` (Monday = 0 … Sunday = 6, from ATUS 1 = Sunday … 7 = Saturday),
`duration_min`, and `diary_min` (minutes since the 04:00 diary start, so activities after midnight sort after the evening).

## 2. Split (`src/split.py`)
Respondents, not rows, are split 70/15/15 with a fixed seed (42). Every activity of a person is in exactly one set.

| Set | Respondents | Activities |
|---|---|---|
| train | 5,984 | 82,304 |
| val | 1,282 | 17,883 |
| test | 1,282 | 17,789 |

A row-level split would place ~18 activities of the same person in different sets and overstate test performance.

## 3. Features (`src/features.py`)
| Feature | Definition |
|---|---|
| `hour` | Start hour of the slot |
| `day_of_week` | Monday = 0 … Sunday = 6 |
| `duration_min` | Activity duration in minutes |
| `historical_completion_rate` | Share of the category's training activities at that hour, scaled so the category's busiest hour = 1; unseen pairs = 0 |
| `schedule_density` | Number of other activities the person has that day |
| `gap_since_last_same_category_hrs` | Hours since the person last started an activity of the same category that day; 24 if none |
| `category_work` … `category_rest` | One-hot, fixed order |

The completion-rate lookup (`completion_rate_lookup.csv`, 6 × 24) is **fitted on train only** and applied unchanged to
validation, test and later to live requests.

Peak hour per category in train: work 12:00, exercise 08:00, leisure 18:00, errand 17:00, social 19:00, rest 22:00.
Before removing diary-start rows, rest peaked at 04:00.

## 4. Labels (`src/negatives.py`)
ATUS contains only activities people did, so each row is a positive (label 1). For each activity a negative (label 0)
is created by moving a copy to another hour of the **same person's day that was taken by a different category and never
by this one**. All other features are kept; the completion rate is recomputed for the new hour. Negatives are generated
inside each split with a fixed seed per split.

| Set | Rows | Positive | Negative | Positive share | Activities with no possible negative |
|---|---|---|---|---|---|
| train | 164,405 | 82,304 | 82,101 | 50.1% | 203 |
| val | 35,733 | 17,883 | 17,850 | 50.0% | 33 |
| test | 35,548 | 17,789 | 17,759 | 50.0% | 30 |

Classes are balanced, so no re-weighting is needed.

## 5. Decisions and changes from the prototype
| # | Decision | Reason |
|---|---|---|
| D8 | Exclude travel | 43% of errand rows; distorted the errand category |
| D9 | Exclude diary-start rows | Artificial 04:00 start times (rest peaked at 04:00) |
| D10 | Gap measured in hours | ATUS has one day per person; the app computes the same from `activity_logs` |
| D11 | Split by respondent before feature engineering | Removes person leakage and lets the completion rate be fitted on train only |
| D12 | Completion rate scaled within each category | A global maximum made small categories (exercise) score near 0 at every hour |
| D13 | Density counts other activities only | Matches what the app knows when asking for a recommendation |
| D14 | Negatives only use hours the category never occupied that day | The prototype could label a slot the person actually used for that category as "not suitable" |

## 6. Limitations
- **Proxy labels.** "Not suitable" means "this person was doing something else then", not "this person would reject this slot".
- **One day per person.** ATUS cannot show weekly habits; the gap feature only looks within a day.
- **U.S. population.** Patterns may differ for Wasaa's users; in-app data (`activity_logs`) is intended to replace ATUS through retraining.
- Survey weights are not used in training; weekends are over-represented (49% of respondents vs 29% weighted).

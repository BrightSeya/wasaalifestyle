# CRISP-DM Phase 1 — Business Understanding

## 1. Business objective
Reduce scheduling conflicts and manual replanning effort for WasaaLifestyle OS users by
recommending when a task or lifestyle activity is most likely to fit their routine.

Users currently coordinate schedules, tasks and routines across separate tools (calendars,
task managers, habit trackers). None of these learn from behaviour, so the user carries the
full burden of deciding *when* to do something and of replanning when things clash.

## 2. Machine learning objective
**Time-slot suitability prediction.**

Given a candidate time slot and features describing the user's scheduling context, classify the
slot as **Suitable (1)** or **Not Suitable (0)** for the requested activity category, then rank
all candidate slots by predicted probability and return the **top 3** with confidence scores.

- Task type: binary classification, used for ranking
- Model: Random Forest classifier (scikit-learn)
- Categories: work, exercise, leisure, errand, social, rest

### Input features (proposal §3.2.4)
| # | Feature | Description |
|---|---|---|
| i | Time of day | Hour of the candidate slot, 0–23 |
| ii | Day of week | 0–6 |
| iii | Activity category | One-hot encoded, six categories |
| iv | Duration | Requested duration in minutes |
| v | Historical completion rate | How often this category is completed at this hour |
| vi | Schedule density | Number of existing events on the candidate day |
| vii | Gap since last same-category activity | Time since the user last did this category |

### Why Random Forest
- Performs well on small-to-medium tabular data.
- Robust to noise and missing values common in user-entered data.
- Provides feature importances, so the system can explain *why* a slot was recommended.
- Trains quickly without special hardware.

## 3. Success criteria
| Criterion | Target | Measured on |
|---|---|---|
| F1-score (Suitable class) | > 0.75 | Held-out test set |
| Hit Rate @ 3 | Reported; higher is better | Test set, grouped per scheduling request |
| Mean Absolute Error | Reported; lower is better | Deviation between recommended and actual slots |
| System Usability Scale | > 68 | User acceptance testing (Sprint 7) |

If results fall below target, the process returns to Data Preparation (features) or Modelling
(hyperparameters) before re-evaluating, as CRISP-DM is iterative.

## 4. Data sources
| Source | Role | Status |
|---|---|---|
| American Time Use Survey (ATUS) 2023, U.S. Bureau of Labor Statistics | Initial training data to solve the cold-start problem | Used now |
| User-generated in-app data (`schedules`, `activities`, `activity_logs`) | Personalised retraining as users interact | Collected from Sprint 3 onward |
| Google Calendar import (optional) | Enriches scheduling history | Sprint 6 |
| Industry partner data | Real-world behaviour, subject to a data-sharing agreement | Not available |

### Why ATUS for the cold start
A new system has no user history, so the model cannot learn personal patterns yet. ATUS records
what thousands of real people did, at what time, on which day and for how long, over a full day.
This gives realistic patterns of *when* each activity category tends to happen, which is the
same question the model must answer. Its activity codes can be mapped to the six Wasaa categories.

**Limitation:** ATUS only records activities people actually did. It has no "not suitable"
examples, so negative examples must be generated during Data Preparation. This is a proxy label
and is stated as a limitation of the model.

## 5. Constraints and assumptions
- Must respond fast enough for the web app to meet the 2-second response target (NFRQ1).
- Served as a separate Python Flask service; Laravel sends features as JSON and never gives the
  service direct database access.
- The rule-based engine in Laravel, not the model, enforces hard constraints (overlaps,
  deadlines). The model only ranks slots.
- Deep learning and reinforcement learning are out of scope (proposal §1.5.3).

## 6. Ethics and privacy
- ATUS is public, anonymised government data.
- In-app behavioural data is used only with explicit user consent (NFRQ6).
- The ML service receives only the features it needs, never names or emails.
- Personal data stays in MySQL behind authenticated access.
- The model's limitations (proxy labels, U.S. population in ATUS) are documented so
  recommendations are treated as suggestions, not decisions.

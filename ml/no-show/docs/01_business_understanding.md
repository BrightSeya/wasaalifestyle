# Sprint 8: Commitment-risk prediction — business understanding

**Status:** The project owner approved the product/data policy on 12 November 2026. Institutional or supervisor approval for any user study or research use of user history must still be confirmed before collection or evaluation.

## 1. Purpose and scope

Wasaa is intended to alert a user when a future scheduled commitment appears at risk based on that user's past behavior. The prediction should support a user decision; it must not automatically cancel, move, or mark a commitment as missed. Sprint 8 adapts the model work in [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling) to Wasaa's Laravel schedule and Python service boundary.

This is not a direct deployment of the prototype. The prototype's API accepts a `bookingId` that it resolves from its own synthetic booking data. Its input features also include provider, household, verification and payment-domain fields that are not Wasaa schedule fields. Those IDs and features cannot be used to identify or score Wasaa records. The reusable starting points are the model/evaluation workflow, calibration approach, baseline-first sequence, and leakage checks. The prototype dataset and its metrics must be identified as synthetic and must not be presented as evidence of real Wasaa-user performance.

## 2. Repository findings

The findings below were checked against the current application code and migrations:

| Area | Current behavior | Consequence for prediction |
|---|---|---|
| Scheduled commitments | `schedules` records have a user, category, start/end times, priority, recurrence fields, and a status enum (`pending`, `active`, `completed`, `missed`). | The schedule is a possible prediction target, but status alone is not yet a reliable observed outcome. |
| Schedule outcome capture | The application now stores owner-recorded occurrence outcomes linked to a schedule and occurrence start. An occurrence end-time snapshot is stored for new records so later schedule edits do not rewrite its duration. | Explicit `completed` and `missed` outcomes can supply labels; `skipped`, `cancelled`, and unreported outcomes are not binary labels. Older rows without an end-time snapshot are excluded from feature preparation. |
| Recurrence | Recurring schedule occurrences are generated for display/logic; one stored schedule can represent multiple dates. | A training example and prediction target must be an individual occurrence, not the recurring schedule master row. |
| Activity history | `activity_logs` stores `completed`, `missed`, or `skipped` for a linked `Activity`, with `scheduled_for` and optional `logged_at`. Users choose the outcome in the Activities flow. | These are user-reported outcomes for routine Activities. They are not linked to Schedule entries and cannot automatically be treated as Schedule labels. |
| Missing outcome | No log may mean the user did not report an outcome. | An unlogged occurrence must not silently be labeled as missed. |
| Model reference | The external prototype identifies its booking data as synthetic and looks up features using its own booking ID. | Wasaa must derive a privacy-limited feature payload from its own records; it cannot send a Wasaa schedule ID and expect the prototype dataset to resolve it. |

Relevant Wasaa source files: `web/database/migrations/2026_09_29_145543_create_schedules_table.php`, `web/database/migrations/2026_09_29_145552_create_activity_logs_table.php`, `web/app/Http/Requests/ScheduleRequest.php`, `web/app/Http/Controllers/ScheduleController.php`, and `web/app/Http/Controllers/ActivityController.php`.

## 3. Approved prediction unit and label policy

### Prediction unit

One future occurrence of a user's schedule entry. For a recurring entry, each generated occurrence is treated independently. Only future occurrences that have not already been completed, cancelled, or otherwise closed are eligible.

### Approved outcome labels

| Recorded outcome for an occurrence | Approved model treatment |
|---|---|
| User explicitly records `completed` after the occurrence | Negative class: the commitment was completed. |
| User explicitly records `missed` after the occurrence | Positive class: the commitment was missed. |
| User records `skipped`, cancels, deletes, or changes the occurrence before it is due | Exclude from the binary training label. |
| No explicit outcome is recorded | Unknown; exclude from training and do not count as a miss. |

The positive label is an explicit, user-reported outcome, not an independent observation of the user's behavior. The wording shown to users and the report must reflect that limitation.

### Required data-capture decision

Before there can be reliable schedule-occurrence labels, the product needs an explicit outcome-recording flow for each occurrence. The current `activity_logs` table cannot provide that link because it references `activities`, not `schedules`, and a recurring schedule master can have many occurrences. Add a dedicated occurrence-outcome record with a stable schedule link, occurrence start, outcome (`completed`, `missed`, or `skipped`), and record time; enforce one outcome per schedule occurrence. Keep unknown and cancelled/deleted occurrences distinct from the binary model labels. This approved outcome-capture work must be completed before preparing a Wasaa schedule-history training set.

Do not use existing `ActivityLog` rows as labels or model features in the initial version. They describe different Activity records and are not linked to Schedule occurrences. The initial model's user-history features must come only from that user's earlier explicitly recorded schedule-occurrence outcomes.

## 4. Approved inference features and privacy boundary

The approved candidate features known before the occurrence are category, scheduled hour/day, duration, lead time, priority, schedule density, and counts/rates derived only from the same user's earlier explicitly labeled schedule occurrences. Retain only features supported by the prepared training data and record the final set in the model card.

The inference request should contain derived feature values only. It should not contain the user's name, email address, schedule title, description, free-text notes, or raw activity history. The model service should not query the Laravel database. Laravel remains responsible for authentication, ownership checks, selecting eligible occurrences, and storing a minimal assessment result.

For each occurrence, all history-derived values must be calculated using records strictly earlier than the prediction time. No outcome, completion timestamp, later activity, or post-event state may enter the features. Outcome records follow the related schedule's retention and are deleted when that schedule or user account is deleted. Do not export raw user history outside the application database or commit it to Git.

## 5. Approved user-facing safeguards

- Prediction alerts are opt-in, off by default, and delivered in the application only.
- The initial alert is scheduled one hour before the eligible occurrence; the alert lead time can be revisited after user evaluation.
- The feature remains disabled when model/data readiness criteria are not met or the model service is unavailable.
- Alerts use cautious language and explain that the score is an estimate, not a certainty.
- Users can dismiss alerts; generate at most one alert per occurrence for an assessment.
- Predictions do not change, cancel, or mark schedule entries automatically.
- A suggested alternative time is a separate user action and must pass Wasaa's existing conflict checks at confirmation time.
- Do not place raw personal data or prediction payloads in application or model-service logs.

The project owner approved these safeguards on 12 November 2026. Do not invent a numeric minimum-history count or probability threshold now: set both from the prepared data and validation results, report the alert volume and precision, and keep predictions disabled until those gates are met. Confirm any institutional review or consent requirements before collecting or evaluating user outcomes.

## 6. Evidence and go/no-go criteria for model work

The model/data issue should not claim readiness until the team can report:

1. The approved outcome definition and its user-facing recording method.
2. Counts of eligible labeled occurrences and distinct users, with unknown and excluded outcomes reported separately.
3. A chronological training/validation/held-out split that prevents future outcomes from influencing earlier predictions.
4. A baseline measured before fitting the adapted model.
5. Held-out discrimination and probability-calibration results, plus the alert volume/precision at the chosen threshold.
6. Whether the evidence comes from real, approved Wasaa outcomes or synthetic fixtures.

If there are not enough approved Wasaa outcomes, synthetic data may be used to test the pipeline and interface contracts only. In that case, keep real-user predictions and alerts disabled, clearly label all synthetic results, and collect properly consented outcomes before making a user-performance claim. Do not choose an arbitrary minimum record count or alert threshold; justify those choices using the dataset, validation results, and project-owner approval.

## 7. Decisions and remaining gate

| Decision | Approved policy |
|---|---|
| Prediction unit | One individual schedule occurrence; each recurrence is treated separately. |
| Positive/negative labels | Explicit `missed` / explicit `completed`, recorded after the occurrence. |
| Excluded outcomes | Skipped, cancelled, deleted, and unreported occurrences are excluded; missing records are not misses. |
| Outcome capture | Add an occurrence-level record linked to schedule and occurrence time before preparing Wasaa training data. |
| Existing `ActivityLog` data | Not used in the initial model because it is not linked to schedules. |
| Inference features | Approved pre-event schedule context plus only that user's earlier explicit schedule-occurrence outcomes; no titles or free text. |
| Alerts | Opt-in, off by default, in-app only, one hour before the occurrence, at most once per occurrence. |
| User control | Alerts are estimates, can be dismissed, and never change a schedule automatically. Alternatives require user confirmation and a final conflict check. |
| Data retention | Outcome records follow schedule retention and are removed with the schedule/account; no raw exports or Git commits. |
| Data/model readiness | Synthetic data is for pipeline tests only. No real-user predictions or alerts until sufficient approved Wasaa data and held-out evaluation support them. |
| Numeric history/score thresholds | Set from data and validation; no arbitrary fixed value is approved. |

**Remaining gate:** confirm any supervisor or institutional approval needed before collecting or evaluating participant/user outcomes. This does not change the approved product policy above.

## 8. Next work after this research decision

Occurrence-level outcome capture was delivered in Sprint 8 issue #119. Issue #110 now implements the leakage-safe preparation pipeline and majority baseline. Only proceed to model adaptation if approved data and the baseline support a meaningful evaluation. The model source remains [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling), but Wasaa must use its own approved labels, feature mapping, and validation evidence.

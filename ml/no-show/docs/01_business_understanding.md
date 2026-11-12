# Sprint 8: Commitment-risk prediction — business understanding

**Status:** Research draft for review. The label policy and use of real user history require project-owner/supervisor approval before implementation trains or serves a user-facing model.

## 1. Purpose and scope

Wasaa is intended to alert a user when a future scheduled commitment appears at risk based on that user's past behavior. The prediction should support a user decision; it must not automatically cancel, move, or mark a commitment as missed. Sprint 8 adapts the model work in [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling) to Wasaa's Laravel schedule and Python service boundary.

This is not a direct deployment of the prototype. The prototype's API accepts a `bookingId` that it resolves from its own synthetic booking data. Its input features also include provider, household, verification and payment-domain fields that are not Wasaa schedule fields. Those IDs and features cannot be used to identify or score Wasaa records. The reusable starting points are the model/evaluation workflow, calibration approach, baseline-first sequence, and leakage checks. The prototype dataset and its metrics must be identified as synthetic and must not be presented as evidence of real Wasaa-user performance.

## 2. Repository findings

The findings below were checked against the current application code and migrations:

| Area | Current behavior | Consequence for prediction |
|---|---|---|
| Scheduled commitments | `schedules` records have a user, category, start/end times, priority, recurrence fields, and a status enum (`pending`, `active`, `completed`, `missed`). | The schedule is a possible prediction target, but status alone is not yet a reliable observed outcome. |
| Schedule outcome capture | `ScheduleRequest` accepts date/time/category/priority/recurrence, not an outcome. `ScheduleController` creates and edits schedule details but does not record completion or missed outcomes. | There is no current user workflow that reliably supplies training labels for schedule occurrences. |
| Recurrence | Recurring schedule occurrences are generated for display/logic; one stored schedule can represent multiple dates. | A training example and prediction target must be an individual occurrence, not the recurring schedule master row. |
| Activity history | `activity_logs` stores `completed`, `missed`, or `skipped` for a linked `Activity`, with `scheduled_for` and optional `logged_at`. Users choose the outcome in the Activities flow. | These are user-reported outcomes for routine Activities. They are not linked to Schedule entries and cannot automatically be treated as Schedule labels. |
| Missing outcome | No log may mean the user did not report an outcome. | An unlogged occurrence must not silently be labeled as missed. |
| Model reference | The external prototype identifies its booking data as synthetic and looks up features using its own booking ID. | Wasaa must derive a privacy-limited feature payload from its own records; it cannot send a Wasaa schedule ID and expect the prototype dataset to resolve it. |

Relevant Wasaa source files: `web/database/migrations/2026_09_29_145543_create_schedules_table.php`, `web/database/migrations/2026_09_29_145552_create_activity_logs_table.php`, `web/app/Http/Requests/ScheduleRequest.php`, `web/app/Http/Controllers/ScheduleController.php`, and `web/app/Http/Controllers/ActivityController.php`.

## 3. Proposed prediction unit and label policy

### Prediction unit

One future occurrence of a user's schedule entry. For a recurring entry, each generated occurrence is treated independently. Only future occurrences that have not already been completed, cancelled, or otherwise closed are eligible.

### Proposed outcome labels

| Recorded outcome for an occurrence | Proposed model treatment |
|---|---|
| User explicitly records `completed` after the occurrence | Negative class: the commitment was completed. |
| User explicitly records `missed` after the occurrence | Positive class: the commitment was missed. |
| User records `skipped`, cancels, deletes, or changes the occurrence before it is due | Exclude from the binary training label unless the project owner approves a separate, precise definition. |
| No explicit outcome is recorded | Unknown; exclude from training and do not count as a miss. |

The positive label is therefore an explicit, user-reported outcome, not an independent observation of the user's behavior. The wording shown to users and the report should reflect that limitation.

### Required data-capture decision

Before there can be reliable schedule-occurrence labels, the product needs an explicit outcome-recording flow for each occurrence. The current `activity_logs` table cannot provide that link because it references `activities`, not `schedules`, and a recurring schedule master can have many occurrences. The implementation issue should record occurrence-level outcomes with a stable link to the schedule and occurrence time, while preserving the distinction between completed, missed, skipped/cancelled, and unknown. The project owner must approve the exact schema and interaction before that work begins.

Existing `ActivityLog` history may be considered as an additional historical-behavior feature only after the team defines a valid mapping to schedule categories and time periods. Do not join records just because their dates or categories happen to match.

## 4. Proposed inference features and privacy boundary

Candidate features known before the occurrence include category, scheduled hour/day, duration, lead time, priority, schedule density, and counts/rates derived only from the same user's earlier explicitly labeled occurrences. The exact feature set must be justified by the training data and recorded in the model card.

The inference request should contain derived feature values only. It should not contain the user's name, email address, schedule title, description, free-text notes, or raw activity history. The model service should not query the Laravel database. Laravel remains responsible for authentication, ownership checks, selecting eligible occurrences, and storing a minimal assessment result.

For each occurrence, all history-derived values must be calculated using records strictly earlier than the prediction time. No outcome, completion timestamp, later activity, or post-event state may enter the features. The team should define retention and deletion behavior for stored assessments alongside the data-capture design.

## 5. User-facing safeguards

Proposed first release behavior, pending approval:

- Prediction alerts are opt-in and delivered in the application only.
- The feature remains disabled when model/data readiness criteria are not met or the model service is unavailable.
- Alerts use cautious language and explain that the score is an estimate, not a certainty.
- Users can dismiss alerts; the same occurrence should not repeatedly generate the same alert.
- Predictions do not change, cancel, or mark schedule entries automatically.
- A suggested alternative time is a separate user action and must pass Wasaa's existing conflict checks at confirmation time.
- Do not place raw personal data or prediction payloads in application or model-service logs.

The project owner/supervisor must confirm opt-in defaults, the minimum history rule, alert timing, threshold/alert-rate policy, and retention period before these behaviors are treated as final requirements.

## 6. Evidence and go/no-go criteria for model work

The model/data issue should not claim readiness until the team can report:

1. The approved outcome definition and its user-facing recording method.
2. Counts of eligible labeled occurrences and distinct users, with unknown and excluded outcomes reported separately.
3. A chronological training/validation/held-out split that prevents future outcomes from influencing earlier predictions.
4. A baseline measured before fitting the adapted model.
5. Held-out discrimination and probability-calibration results, plus the alert volume/precision at the chosen threshold.
6. Whether the evidence comes from real, approved Wasaa outcomes or synthetic fixtures.

If there are not enough approved Wasaa outcomes, synthetic data may be used to test the pipeline and interface contracts only. In that case, keep real-user predictions and alerts disabled, clearly label all synthetic results, and collect properly consented outcomes before making a user-performance claim. Do not choose an arbitrary minimum record count or alert threshold; justify those choices using the dataset, validation results, and project-owner approval.

## 7. Decisions requiring approval

- [ ] Confirm the prediction unit is an individual schedule occurrence.
- [ ] Approve explicit `completed` versus `missed` as the binary outcome and exclude skipped/cancelled/unknown records.
- [ ] Approve adding occurrence-level schedule outcome capture before model training on Wasaa history.
- [ ] Approve whether existing Activity logs may be used as supplemental features, and define a valid mapping if so.
- [ ] Approve opt-in behavior, minimum-history policy, alert timing/threshold policy, and assessment retention.
- [ ] Confirm what consent or institutional review is required before collecting or evaluating user history.

## 8. Next work after this research decision

Once the decisions above are approved, update the related Sprint 8 implementation issues so occurrence-level outcome capture is an explicit prerequisite. Then prepare the leakage-safe dataset and baseline. Only proceed to model adaptation if the data and baseline report support a meaningful evaluation. The model source remains [`BrightSeya/scheduling`](https://github.com/BrightSeya/scheduling), but Wasaa must use its own approved labels, feature mapping, and validation evidence.

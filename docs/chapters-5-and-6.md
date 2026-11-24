# CHAPTER FIVE
## SYSTEM IMPLEMENTATION, TESTING AND EVALUATION

### 5.1 Introduction

This chapter describes the implementation and evaluation of WasaaLifestyle OS, a web-based smart scheduling system. The work combines a Laravel application for user and schedule management with a Python Flask service that ranks candidate time slots using a Random Forest classifier. Deterministic application rules detect schedule conflicts and retain control over whether a suggested time may be accepted. The application also records lifestyle activity outcomes, provides notifications and administrative functions, and supports optional read-only Google Calendar import.

The chapter follows the implementation and evaluation sequence used in the project. It first describes the development and demonstration environments, then the system modules and the data and model pipeline. It reports software verification, model validation, performance measurements, and the user-provided UAT and SUS results. Version-control and implementation evidence are described in Section 5.5. A later Sprint 8 extension adds an optional commitment-risk workflow. Its software boundary and safeguards are implemented, but the repository contains no approved real-user training dataset or evaluated model artifact; consequently, the extension remains disabled and no real-user predictive performance is claimed.

The system was developed in response to the objectives in Chapter One: analysing scheduling challenges and existing tools, developing an integrated web scheduling system with machine-learning time recommendations and rule-based conflict detection, and evaluating its performance, predictive accuracy, usability and algorithmic efficiency. The implementation was refined over Agile sprints, while the recommendation model followed the CRISP-DM stages described in Chapter Three.

### 5.2 Implementation Environment

#### 5.2.1 Hardware and Deployment Environment

Development and local verification used a Windows environment. The demonstration system was provisioned on a Microsoft Azure virtual machine running Ubuntu 24.04.5 LTS. The selected VM size was Standard B2s. Nginx serves the Laravel application, PHP-FPM executes the application, MySQL stores application records, and Gunicorn serves the Python recommendation API on the VM loopback interface. The service is not intended to accept direct Internet traffic.

The Azure demonstration was accessed through its public IP over HTTP during the reported setup. A domain and HTTPS configuration were not verified for the final application URL. The apex domain `wasaalifestyle.me` was retained for GitHub Pages and was not used for the Azure application. The VM and its associated resources may incur costs while they remain provisioned; therefore, the demonstration does not establish an indefinitely free hosting arrangement.

| Environment item | Recorded value |
|---|---|
| Development computer and operating-system build | <mark>[PLACEHOLDER: Enter the laptop model and Windows version from System Information.]</mark> |
| VM provider and subscription | Microsoft Azure; Azure for Students |
| VM name and size | `wasaa-demo-vm`; Standard B2s |
| VM operating system | Ubuntu Server 24.04 LTS (SSH banner reported Ubuntu 24.04.5 LTS) |
| Web server | Nginx 1.24.0 (Ubuntu package) |
| Public access used during setup | VM public IP over HTTP; do not enter real user passwords through this HTTP address |
| Final demonstration URL and HTTPS status | <mark>[PLACEHOLDER: Enter the assessment URL and confirm whether HTTPS was configured and tested.]</mark> |
| Budget alert and final VM state | <mark>[PLACEHOLDER: Enter the Azure budget-alert threshold and whether the VM was stopped or retained for assessment.]</mark> |

#### 5.2.2 Software, Services and Configuration

The web application uses Laravel 12 with PHP. Its browser assets are compiled using Vite and styled with Tailwind CSS; Chart.js renders the activity-completion chart. MySQL is the application database in the local and Azure configurations described during deployment. Automated Laravel tests use an isolated SQLite database. The machine-learning service uses Python, Flask, pandas, NumPy, scikit-learn and joblib. In the Azure setup, Gunicorn runs the Flask application as a systemd service bound to `127.0.0.1:5001`.

| Component | Recorded version or configuration | Purpose |
|---|---|---|
| Laravel application | Laravel 12; deployed PHP CLI reported 8.3.6 | Authentication, application workflows, persistence and business rules |
| PHP web runtime | PHP-FPM behind Nginx | Executes Laravel requests |
| Frontend | Blade, JavaScript, Vite, Tailwind CSS, Chart.js | User interface, styling and activity visualisation |
| Database | MySQL 8.0.46 on the reported VM; SQLite in CI | Stores account, schedule and application data |
| Slot recommender service | Python 3.12.3 on the reported VM; Flask 3.1.3; Gunicorn | Scores candidate hours and returns time-slot recommendations |
| Python model libraries reported on VM | NumPy 2.4.6, pandas 3.0.6, scikit-learn 1.9.1, joblib 1.6.0 | Feature processing, model inference and artifact loading |
| Recommender bind | `127.0.0.1:5001` in the tracked Azure service template | Keeps the prediction service private to the host |
| Mail transport | Inbox delivery was not confirmed for the demonstration | Email verification and password-related messages depend on server mail configuration |
| Risk-model controls | Laravel flag defaults to `false`; Python model flag defaults to `false`; alert threshold is unset | Prevents unapproved or unevaluated commitment-risk predictions and alerts |

Credentials and host-specific values are supplied through environment configuration and are excluded from Git. The tracked `.env.example` provides variable names without secret values. The Azure runbook records database creation, file permissions, asset build, systemd service setup, Nginx configuration, and rollback steps. A successful local check of application code does not establish the state of every private value on the deployed VM.

#### 5.2.3 Deployment and Reproducibility

The deployment configuration describes a single Ubuntu virtual machine hosting the Laravel web application, MySQL database and Python recommender service. Nginx serves the `web/public` directory and forwards PHP requests to PHP-FPM. The Python service runs separately under Gunicorn and is bound to loopback. The recommender health endpoint returned `{"model_loaded":true,"status":"ok"}` when checked on the VM. The application page and compiled assets were later served successfully through the configured Nginx virtual host.

The slot model and generated ATUS files are not committed as large or private artifacts. Reproducing the training process requires obtaining the public ATUS 2023 activity and respondent source files and following the preparation and training scripts in `ml/slot-recommender/`. Reproducing the Laravel environment requires PHP and Composer dependencies, Node dependencies for the asset build, a configured database and environment values. The no-show preparation and training pipeline stores derived data, reports and model artifacts in ignored private storage and must not be supplied with real user records without the required approval.

The live Azure checks were reported by the user during deployment setup. The repository does not contain independent verification of the VM's current state, effective network security rules, HTTPS, production SMTP delivery, or final Google OAuth callback. The commitment-risk model was not installed or enabled on the VM. The system must therefore be described as a demonstration deployment with outstanding production-hardening checks, not as a verified public production service.

<mark>[PLACEHOLDER: Insert a redacted screenshot of the Azure VM overview and the Nginx-served Wasaa page; include the capture date and hide credentials and any personal data.]</mark>

*Figure 5.1: Azure demonstration environment and application access.*

### 5.3 System Implementation

#### 5.3.1 Implemented Modules and Traceability

The Laravel application owns identity, authorization, validation, persistence, notifications and schedule changes. The Python model service scores a bounded JSON request and returns recommendations; it does not read the application database or save schedules. This separation keeps hard schedule constraints in deterministic Laravel rules rather than in the prediction model.

| Module | Implemented behaviour | Proposal requirement coverage |
|---|---|---|
| User identity and account settings | Registration, verification, login, profile editing, photo upload, preferences, password change, account deletion and account suspension controls | FRQ1, FRQ2 |
| Activity tracking | Create and manage routine activities; record completed, missed or skipped status and review history | FRQ5, FRQ6, FRQ12 |
| Schedule manager | Create, view, edit and delete schedule entries; store title, category, priority, time and recurrence; provide list, day and week views | FRQ3, FRQ4, FRQ8 |
| Conflict engine | Detect overlaps across one-off and recurring occurrences, report priority clashes and require explicit confirmation to keep a conflict | FRQ7 |
| Slot recommendation | Call Flask for candidate-hour scores and display up to three ranked suggestions with confidence information | FRQ9, FRQ10 |
| Notifications | Display unread notifications and conflict alerts, and support read/dismissal behavior | FRQ11 |
| Dashboard and activity summaries | Present the upcoming schedule, notification summaries and activity completion information | FRQ12 |
| Administration | Provide user-management and audit functions to authorized administrators | Administrator requirements in Chapter Four |
| Google Calendar | Support an optional, user-authorized read-only event import | External-service requirements in Chapter Four |
| Commitment-risk add-on | Capture occurrence outcomes, derive leakage-limited features, expose a private prediction contract, and implement default-off assessments, opt-in alerts and conflict-checked alternatives | Sprint 8 extension; not an original Chapter Four requirement |

The exact requirement numbering and wording for administrator, security and external-service requirements should match the final Chapter Four version used in the submitted thesis.

#### 5.3.2 Database and Data Implementation

Laravel migrations define the application schema. The principal tables store users and preferences, schedules, routine activities and their logs, recommendation records, notifications, Google Calendar connections, administrator audit records, schedule-occurrence outcomes and commitment-risk assessments. Foreign keys and uniqueness constraints support ownership and lifecycle behavior. For recurring schedule entries, a stored schedule is expanded into dated occurrences by application logic rather than copied into a separate row for every future date.

| Table | Main purpose |
|---|---|
| `users` | Account identity, role, status, profile, preferences and session version |
| `activities` | User-defined recurring routines and their settings |
| `activity_logs` | User-reported daily completion, missed or skipped activity outcomes |
| `schedules` | One-off and recurring tasks or events |
| `recommendations` | Suggested time slots and their state |
| `notifications` | Conflict, system and optional commitment-risk messages |
| `google_calendar_connections` | Encrypted Google OAuth connection data |
| `admin_audit_logs` | Auditable administrator actions |
| `schedule_occurrence_outcomes` | Explicit completed, missed or skipped result for a particular schedule occurrence |
| `schedule_risk_assessments` | Minimal optional risk assessment metadata associated with an occurrence |

The no-show occurrence outcome is distinct from the daily activity log. A recurring schedule represents multiple occurrences, so each outcome is tied to a specific schedule and occurrence start. Newly captured outcomes preserve an occurrence-end snapshot; older records without a reliable end snapshot are excluded from model preparation. Schedule and account deletion cascade to related outcomes and assessments.

#### 5.3.3 Authentication, Authorisation and Account Management

Registration and login are handled by Laravel. New accounts must verify a signed, time-limited email link before accessing protected application pages. Authentication and verification routes are throttled. Middleware restricts schedule and activity functionality to authenticated users and administrative functions to the administrator role. Ownership checks prevent one user from viewing or changing another user's schedules, activities, recommendations and notifications. A session-version value supports immediate invalidation when an account is suspended or its access state changes.

Users can update profile information, upload an allowed profile photo, adjust availability and notification preferences, change their password, and permanently delete their account after confirming the action and re-entering their password. Sensitive account controls are grouped in collapsible settings sections. Account deletion removes associated records according to the database relationships. Password-reset and verification email delivery depends on a correctly configured mail transport; a log mailer records messages without delivering them to an inbox.

#### 5.3.4 Activity and Schedule Management

An activity represents a recurring routine described by its name, category, preferred time, weekly frequency and duration. Users can log an activity as completed, missed or skipped. The activity page presents recent history, streak and completion summaries. A repeated log for the same activity and day updates the recorded result rather than creating an unrelated duplicate entry.

A schedule entry represents a task or event with a title, category, start and end, priority, optional description and recurrence setting. Recurrence can be none, daily or weekly. The application expands recurring schedules into occurrences for list, day, week and conflict-checking operations. The dashboard presents upcoming entries and account activity. The interface also includes light and dark themes, settings for notification preferences, and profile-photo controls.

#### 5.3.5 Conflict Detection and Resolution

The `ConflictChecker` applies interval-overlap rules to the candidate schedule and existing one-off or recurring occurrences. Two intervals overlap when the existing start is before the candidate end and the existing end is after the candidate start. The checker excludes the schedule being edited and identifies any lower-priority candidate that overlaps a higher-priority existing commitment.

When a conflict is found, the application reports it before saving. A user can change the time or explicitly confirm that the overlap is intentional. The application rechecks the schedule at confirmation time because another entry may have changed while the warning was displayed. A confirmed conflict creates a linked in-app notification. Accepting a recommendation follows the same conflict-check and confirmation process; the model does not override the user's schedule rules.

#### 5.3.6 Notifications and User Preferences

Notifications include schedule conflict alerts and other system messages. Users can view unread messages, mark notifications as read and dismiss supported alerts. Settings include availability windows, productivity hours, break preferences and notification preferences. A commitment-risk alert preference is also available, but it cannot produce a risk alert while the server-side feature flag or evaluated threshold is unavailable.

Commitment-risk alerts, when enabled after a suitable evaluation, are in-app only and are generated approximately one hour before an eligible occurrence. They require the user's explicit opt-in, an enabled service, a numeric configured threshold and a successful assessment meeting that threshold. Alerts are deduplicated and dismissible. They do not change or cancel a schedule, send email or push notifications, or mark an occurrence as missed. Alternative times are obtained from the existing recommendation service and must be explicitly accepted after another conflict check.

#### 5.3.7 Google Calendar Integration

The optional Google Calendar integration uses a read-only authorization scope. After the user connects an account, the application can preview events within a limited date range, identify duplicates and conflicts, and import supported timed events into Wasaa. It does not modify the Google Calendar. All-day events and timed events crossing a local calendar day are not supported by the current importer. OAuth connection data is encrypted using the Laravel application key. A final production callback URI and HTTPS flow were not verified in the evidence available for this chapter.

#### 5.3.8 Administration, Analytics and Audit

Administrative routes are restricted to authorized administrator accounts. The administrative interface supports user management, analytics and system activity review. Administrative actions are written to an audit log so that actions such as account status changes can be traced. Ordinary users cannot access administrator routes or records. The reporting functions summarize application data; they do not represent independent research statistics about a wider user population.

#### 5.3.9 Security Hardening

Security controls include server-side request validation, ownership authorization, CSRF protection through Laravel, rate limiting on authentication-related endpoints, email verification, restricted administrative routes, secure password handling, account deletion confirmation, encrypted calendar tokens, response security headers and production configuration guidance. The machine-learning service is bound to the loopback interface in the Azure unit template and is not meant to be exposed as a public unauthenticated endpoint. Large datasets, serialized models, local `.env` files and private generated no-show artifacts are excluded from Git by repository rules and CI sanity checks.

The project has automated tests for application access controls and security-related behavior. No independent penetration test, external security audit or formal privacy-impact assessment is claimed. The HTTP-only Azure demonstration is not appropriate for real user credentials; HTTPS, production mail delivery, backup/restore validation and live network-rule verification remain to be documented separately.

#### 5.3.10 Dataset Description: Slot Recommender

The slot recommender was trained using the 2023 American Time Use Survey (ATUS) public-use activity and respondent data from the U.S. Bureau of Labor Statistics (U.S. Bureau of Labor Statistics, 2024). After cleaning, the project reports 117,976 activity episodes from 8,548 respondents. Activities are mapped to six Wasaa categories: work, exercise, leisure, errand, social and rest. Travel records, unmapped records and artificial diary-start rows are excluded. ATUS records activities that respondents performed; it does not record whether a user would accept a suggested alternative time.

Each observed activity episode is treated as a positive suitability example. The pipeline generates at most one negative example by moving an episode to another hour occupied by a different activity category on the same respondent's diary day, excluding hours already used by that category. Negative examples are therefore constructed proxies, not direct user judgments that a proposed slot is unsuitable. No private Wasaa user dataset is used to train the committed slot model.

#### 5.3.11 Data Preparation and Partitioning

Respondents are partitioned before feature engineering so that records from one respondent do not appear in multiple partitions. The split uses a fixed random seed and a 70/15/15 division by respondent. The training partition contains 5,984 respondents and 82,304 observed episodes; validation contains 1,282 respondents and 17,883 episodes; test contains 1,282 respondents and 17,789 episodes. Negative examples are generated separately within each partition.

| Partition | Final modelling rows | Positive | Constructed negative |
|---|---:|---:|---:|
| Training | 164,405 | 82,304 | 82,101 |
| Validation | 35,733 | 17,883 | 17,850 |
| Test | 35,548 | 17,789 | 17,759 |

The model uses twelve columns: hour, weekday, requested duration, historical completion rate, schedule density, hours since the last activity in the same category and six one-hot category fields. The completion-rate lookup is fitted on training data only and applied unchanged to validation, test and live inference. Survey weights are not applied. ATUS provides one diary day per respondent, so the data cannot represent long-term weekly habits.

#### 5.3.12 Model Training and Selection

The selected model is a scikit-learn Random Forest classifier with 300 trees, maximum depth 15, minimum leaf size 2 and random seed 42. Hyperparameter selection uses five-fold GroupKFold by respondent. Two grid-search iterations were recorded; the second obtained a grouped cross-validation F1 of 0.7299. The cross-validation result is separate from the final comparative validation metrics.

Each model's F1 threshold is selected on validation data. The current validation F1 values are 0.7442 for the Random Forest, 0.6922 for the completion-rate-only baseline and 0.6993 for logistic regression. The Random Forest validation Hit Rate@3 is 0.3612 and MAE is 3.682 hours. Its untouched held-out test F1 is 0.7510 at threshold 0.3823, narrowly meeting the proposal's greater-than-0.75 target; test Hit Rate@3 is 0.3675 and MAE is 3.597 hours. The model metadata records validation and test metrics separately.

The leading stored feature importances are historical completion rate (0.2939), hour (0.1813), time since the previous same-category activity (0.1671), duration (0.1477), and schedule density (0.0776). Feature importance indicates the model's use of a feature in its fitted predictions; it does not establish causation.

#### 5.3.13 Model Serving and Integration with the Web Application

The Flask recommender provides `/health`, `POST /predict` and a protected `/retrain` endpoint. At startup it loads the serialized Random Forest and completion-rate lookup. Laravel sends a bounded request containing the activity category, day, duration, schedule context and same-category gap. Flask scores candidate hours and returns the three highest-ranked suggestions with confidence and suitability information. Laravel stores the recommendations and owns their acceptance, rejection and conflict checks. The retraining endpoint checks the configured API key and returns a failure without replacing the active model if training fails.

The live model artifact is generated locally and excluded from Git. Therefore, the committed model metadata and validation comparisons can be examined from the repository, but the exact artifact used in a deployment must be identified separately if a reproducibility claim is required.

#### 5.3.14 Commitment-Risk Add-on

The Sprint 8 commitment-risk extension uses the Logistic Regression and Platt-calibration model implementation from the public `BrightSeya/scheduling` repository and its pinned 21,333-row synthetic bookings dataset. Wasaa fits the source model from this dataset for cold start and bypasses its `bookingId` lookup. Its source label includes both no-shows and cancellations; Wasaa's later occurrence labels distinguish completed (0) and explicitly missed (1), excluding skipped, cancelled and unreported outcomes.

For inference, Laravel derives only pre-occurrence information and the same user's prior explicit outcomes; it sends no IDs or free text. The adapter maps lead time, weekday/hour and prior outcome counts/rates. Wasaa does not collect the source's booking price, provider rating or verification fields, so their model inputs use neutral centers learned from the upstream fit partition; lifestyle categories not found in the service dataset are treated as unknown. Priority, duration and same-category history are not used by this source model. This mapping supports a cold-start demonstration, but it does not establish calibration for Wasaa users. The model card records the mapping, pinned source commit, dataset checksum and synthetic chronological holdout metrics.

Laravel checks schedule ownership and future-occurrence eligibility before constructing a request. Users with no recorded outcomes may receive a cold-start estimate; their history counts are zero and rates null. New outcomes change mapped prior-history inputs on later requests, but model weights do not learn online. The Python endpoint `POST /noshow/predict` rejects missing or unexpected fields and does not query Laravel's database. The upstream synthetic artifact was generated locally and is ignored by Git; it is not included in a deployment. Both Python model flags and Laravel alert controls remain disabled by default. No approved real Wasaa dataset, prepared real-data report or fitted Wasaa model is available. Automated tests verify source-data parity, feature mapping and service contracts, not predictive accuracy for Wasaa users.

The cold-start model reproduces the upstream trainer on the pinned synthetic dataset, and a compatibility test confirms the probabilities match the upstream implementation on its holdout to floating-point precision. Its synthetic holdout ROC-AUC is 0.7507 and Brier score is 0.1358; these values describe the generated source data and do not demonstrate Wasaa-user performance. If deliberately enabled for a local prototype, responses and alerts are labelled synthetic prototype estimates. The Azure demonstration has not been updated with this artifact. A later Wasaa model can replace the cold-start model only after approved outcomes, chronological evaluation, baseline comparison and review. Alerts remain opt-in, in-app, deduplicated and advisory; users control schedule changes and alternatives are conflict-rechecked.

<mark>[PLACEHOLDER: Insert an architecture or sequence diagram of the implemented Laravel–Flask boundary. Mark commitment-risk inference and alerts as disabled until an approved real model and threshold exist.]</mark>

*Figure 5.2: Application and machine-learning service boundaries.*

### 5.4 Testing and Evaluation

#### 5.4.1 Test Strategy and Acceptance Criteria

Evaluation combines automated Laravel tests, Python model and API tests, local HTTP benchmarking, reported UAT/SUS results and review of the available model metadata. Unit tests cover isolated business rules and feature functions. Laravel feature tests exercise application routes, persistence and authorization using a test database and service fakes. Python tests cover data loading, preparation, model utilities and Flask endpoint contracts. Local performance measurements cover complete HTML responses for selected Laravel routes. These forms of evidence answer different questions and are not interchangeable: successful software tests do not establish model quality, user acceptance or secure production operation.

The measured targets recorded for the project are a normal-page response time below two seconds and a mean SUS score greater than 68. The proposal also states a Random Forest F1 target greater than 0.75. The recommender validation result is compared with the available baseline models. No approved acceptance threshold for the no-show model can be reported because the real-data evaluation has not been completed.

#### 5.4.2 Automated Test Results

The latest recorded Sprint 8 verification run reports the following local results:

| Test suite | Recorded result | Scope and qualification |
|---|---|---|
| Laravel application | 230 passed, 4 skipped; 979 assertions | Local PHPUnit suite. Four live recommender integration tests were skipped because the Flask service was not running. |
| Python slot-recommender and API | 107 passed | Pytest suite includes existing recommender behavior and commitment-risk endpoint contract tests using a synthetic test model. |
| No-show preparation/training contracts | 12 passed | Local rerun on 12 November 2026 after adapting the upstream Logistic Regression and Platt method. Synthetic generated rows verify preparation and trainer behavior; this is not real-user model evaluation. |
| Browser/manual risk-alert checklist | Pending in the repository record | Manual UI results are not inferred from automated tests. |

The Laravel tests cover authentication, ownership and access control, schedule occurrences, conflicts, recommendation behavior, notifications, settings and account behavior. Sprint 8 adds cases for outcome eligibility, history timing, disabled or unavailable models, alert timing and deduplication, dismissal, ownership and conflict-checked alternatives. The Python risk API tests cover valid schemas, missing and extra fields, disabled/unavailable model behavior and safe failures.

The previously recorded Laravel and Python recommender run was executed on 12 November 2026 against the Sprint 8 test work associated with PR #128. The test report identifies the issue #116 branch head as `a01e9677f9c717a7441d0dffb7a3ab5c6ddd7bac`, merged as `75a2ed1fad19f989c1de8bedb0e3deb24f33baf9`. After adapting the trainer to the upstream model method, the no-show suite was rerun locally and recorded 12 passing tests, and the recommender suite was rerun with 107 passing tests. This working-tree verification is not a hosted CI run and does not correspond to an Azure deployment; a successful GitHub Actions result should only be stated when captured from GitHub.

<mark>[PLACEHOLDER: Insert a dated screenshot of the final relevant automated test output or GitHub Actions run. If using local output, identify it as local rather than hosted CI.]</mark>

*Figure 5.3: Automated verification evidence.*

#### 5.4.3 Slot-Recommender Evaluation

The Random Forest was compared with the completion-rate-only and logistic-regression baselines on the validation set. Precision, recall and F1 describe the binary suitability classification. Hit Rate@3 measures whether the observed activity hour appears among the three ranked candidate hours. MAE reports the mean hour deviation as calculated by the project evaluation code.

| Model | Precision | Recall | Validation F1 (threshold) | Hit Rate@3 | MAE (hours) |
|---|---:|---:|---:|---:|---:|
| Completion-rate-only baseline | 0.5718 | 0.8766 | 0.6922 (0.2684) | 0.2757 | 4.655 |
| Logistic regression | 0.5829 | 0.8740 | 0.6993 (0.3948) | 0.2703 | 4.711 |
| Random Forest | 0.6307 | 0.9076 | 0.7442 (0.3823) | 0.3612 | 3.682 |

The Random Forest improved the validation Hit Rate@3 by approximately 31 per cent over the completion-rate baseline and reduced the reported MAE by approximately 21 per cent. Thresholds were selected on validation data, and the held-out test was evaluated once at the selected Random Forest threshold: precision 0.6369, recall 0.9149, F1 0.7510, Hit Rate@3 0.3675 and MAE 3.597 hours. This narrowly meets the stated F1 target. The two grid-search iterations had similar cross-validation results, increasing from 0.7284 to 0.7299. Because negative suitability labels are constructed from occupied hours, these results indicate ranking/classification behavior on the prepared proxy dataset rather than users' actual acceptance of suggested times.

<mark>[PLACEHOLDER: Insert a validation model-comparison or feature-importance figure regenerated from the committed model metadata. Label it as validation evidence, not a test-set result.]</mark>

*Figure 5.4: Slot-recommender validation comparison and feature importance.*

#### 5.4.4 Commitment-Risk Software Verification and Evaluation Status

The pipeline and API tests verify data-label rules, pre-event history constraints, leakage rejection, chronological split requirements, service input validation and safe disabled-model behavior. The Laravel tests verify ownership, occurrence eligibility, no-history behavior, unavailable-service handling, opt-in, threshold gating, one-hour timing, alert deduplication, dismissal and alternatives with confirmation-time conflict checks. All four gating conditions—Laravel feature flag, Python model flag, user preference and an evaluation-supported numeric threshold—must be satisfied before model-based alerts can be created.

The tests do not show that the model can identify likely missed commitments in real Wasaa usage. No approved real dataset, per-user sample counts, real-data baseline, held-out model metrics, model card, threshold evaluation or live model artifact was available. The risk flags remain off by default in tracked configuration and the alert threshold is blank. The no-show add-on is therefore software-tested at the contract and safeguards level but has no reported real-data predictive evaluation. Its live Azure deployment and browser alert flow remain unverified.

#### 5.4.5 Performance Testing

The application-level benchmark measured nine routes with three warm-up requests and 30 sequential samples per route. All 270 sampled normal page responses returned HTTP 200 and completed below the two-second target. The run used a local Windows development host, Laravel at `127.0.0.1:8000`, MySQL and a verified active test account. Its recorded baseline was dated 8 November 2026 and tested commit `e4022f0`.

| Route | Median (ms) | P95 (ms) | Maximum (ms) |
|---|---:|---:|---:|
| `/` | 285.1 | 321.1 | 324.5 |
| `/login` | 272.3 | 303.1 | 305.4 |
| `/dashboard` | 297.7 | 314.3 | 316.5 |
| `/notifications` | 292.8 | 369.9 | 377.3 |
| `/activities` | 335.3 | 369.5 | 386.3 |
| `/schedules` | 333.7 | 374.9 | 376.9 |
| `/schedules/week` | 332.4 | 361.3 | 362.0 |
| `/recommendations` | 333.3 | 356.7 | 386.8 |
| `/settings` | 330.4 | 401.3 | 461.5 |

Across the combined measurements, the median was 316.3 ms, the nearest-rank P95 was 369.5 ms and the maximum was 461.5 ms. The local run met the target for its routes, request count and single-client workload. It does not measure browser rendering, remote network delays, Azure response time or concurrent-user performance. The first benchmark attempt returned HTTP 500 because MySQL refused a connection; it yielded no samples and was excluded from the successful baseline.

<mark>[PLACEHOLDER: Insert the local benchmark chart or a redacted benchmark summary screenshot. Caption it as a sequential local HTTP test.]</mark>

*Figure 5.5: Local Laravel HTTP response times across 270 sequential samples.*

#### 5.4.6 User Acceptance Testing and Usability

The user-provided evaluation record states that 20 functional cases were conducted and that 19 passed and one failed, giving a reported pass rate of 95 per cent. The supplied case table marks all 20 cases as passing, which conflicts with the summary. The overall figure is retained here as the reported aggregate, but the failed case cannot be identified from the available record and the detailed table must be reconciled before final submission.

| UAT measure | User-reported result |
|---|---:|
| Defined functional cases | 20 |
| Reported passed | 19 |
| Reported failed | 1 |
| Reported pass rate | 95% |
| Failed case identity and corrected row | <mark>[PLACEHOLDER: Identify the failed test ID and correct its actual-result/status row; the current submitted table marks every case Pass.]</mark> |
| Test date, environment/URL and tested commit | <mark>[PLACEHOLDER: Enter the actual session date(s), tested environment or URL, and exact application commit.]</mark> |
| Consent or institutional approval status | <mark>[PLACEHOLDER: State the actual approval/consent basis used for the participant sessions.]</mark> |

The reported cases included registration, login, invalid credentials, activity creation/edit/deletion, daily and weekly schedules, conflict detection, smart recommendations, no-show display, history, activity completion, Google Calendar synchronization, validation, dashboard statistics and logout. The no-show case is reported in the supplied UAT summary but cannot be matched to a deployed model in the repository; it is not evidence of a real-user prediction. The UAT summary is evidence as reported by the project author; the missing case-level reconciliation limits independent verification.

The user-provided SUS scores cover ten anonymized participants: 80.0, 75.0, 82.5, 72.5, 85.0, 77.5, 70.0, 80.0, 75.0 and 82.5. Their total is 780, giving a mean of 78.0 and a range of 70.0–85.0. The reported mean exceeds the project threshold of 68. The raw ten-item responses and session metadata were not supplied with the result, so the individual scoring cannot be independently recalculated from the record.

| SUS measure | Result |
|---|---:|
| Participants with reported scores | 10 |
| Total of reported scores | 780 |
| Mean score | 78.0 |
| Minimum–maximum | 70.0–85.0 |
| Project benchmark | Greater than 68; reported mean is above the benchmark |
| Raw item responses and valid response record | <mark>[PLACEHOLDER: Confirm ten valid completed questionnaires and insert or retain the anonymized I1–I10 response record in the approved appendix.]</mark> |
| Participant/session profile and dates | <mark>[PLACEHOLDER: Enter the approved anonymized participant description and actual session dates.]</mark> |

The SUS result indicates positive perceived usability among the ten respondents in this evaluation. It does not establish usability for all students or other user groups. The participant count, missing item-level responses and unresolved UAT discrepancy limit the strength of any broader conclusion.

<mark>[PLACEHOLDER: Insert a redacted summary chart of UAT and SUS results. Do not include participant names, email addresses or identifying details.]</mark>

*Figure 5.6: Reported UAT and SUS summary.*

### 5.5 Version Control and Implementation Evidence

WasaaLifestyle OS is maintained in the public GitHub repository `https://github.com/BrightSeya/wasaalifestyle`. The repository contains the Laravel application, Python machine-learning code, deployment templates, documentation, tests and GitHub workflow configuration. The branch-based GitHub process connected planned work to implementation through issues, focused branches, commits and pull requests.

#### 5.5.1 Repository Setup and Access

The repository is a monorepository. The `web/` directory contains Laravel source, migrations, frontend resources and tests; `ml/slot-recommender/` contains the Flask recommender, ATUS processing code and tests; `ml/no-show/` contains the commitment-risk data and model pipeline; `deploy/azure/` contains service and web-server templates; and `docs/` contains architecture, operational, testing and user documentation. The repository is publicly readable. No project-level licence file was present in the reviewed checkout.

<mark>[PLACEHOLDER: Insert a screenshot of the repository overview with the repository name and main directories visible.]</mark>

*Figure 5.7: WasaaLifestyle OS GitHub repository and project organisation.*

#### 5.5.2 Task Planning and Tracking

Work was recorded in GitHub issues using the repository's Bug, Feature and Task templates and grouped into sprint milestones. Sprint 8 commitment-risk work was represented by issues #109–#119. The implementation sequence included occurrence outcomes and dataset preparation, model adaptation and private service endpoint, Laravel assessment integration, opt-in alerts, conflict-checked alternatives, end-to-end test evidence, CI and Azure safeguards, and model/operations documentation. Issues provide traceability between planned work and the associated branches and pull requests.

<mark>[PLACEHOLDER: Insert a dated screenshot of the GitHub milestone or issue list showing the final status of the relevant project issues.]</mark>

*Figure 5.8: Sprint issue tracking and implementation status.*

#### 5.5.3 Branching Strategy

Changes were developed on issue-specific branches and integrated into `main` through pull requests. Branch names used an issue number and a short conventional purpose, for example `116-test-commitment-risk-e2e` and `118-docs-commitment-risk-model-operations`. The local checkout inspected while preparing this chapter was on `118-docs-commitment-risk-model-operations` at commit `a722bab`; this is a documentation-work snapshot and should not be mistaken for the final submitted release commit.

<mark>[PLACEHOLDER: Replace the local snapshot with a dated screenshot of the final submitted branch, commit and clean/known working-tree state.]</mark>

*Figure 5.9: Issue-linked branch and commit used for the final submission.*

#### 5.5.4 Commits and Development History

Commits used focused conventional messages such as `feat:`, `fix:`, `test:`, `docs:` and `chore:`. Issue-linked branches separated changes for review. The project log records implementation and testing decisions, including the preservation of unrelated local edits and the evidence used to verify each sprint change. The final commit count and release tag depend on the repository state at the time of submission.

<mark>[PLACEHOLDER: Insert a dated screenshot of representative issue-linked commits and record the final commit count/release tag if one is created.]</mark>

*Figure 5.10: Selected implementation and verification commits.*

#### 5.5.5 Review and Integration

Pull requests were used to review and integrate completed issue branches. The repository history records Sprint 8 merges including PR #126 for configurable commitment-risk alerts, PR #127 for conflict-checked alternatives, PR #128 for end-to-end verification and PR #129 for CI and Azure deployment safeguards. The reviewed tests and operational limitations were recorded alongside the changes. This account does not infer reviewer feedback or a successful hosted workflow unless shown by the actual GitHub record.

<mark>[PLACEHOLDER: Insert a screenshot of a merged pull request showing its linked issue, merge status, review state and actual CI checks.]</mark>

*Figure 5.11: Pull-request review and integration evidence.*

#### 5.5.6 Project Organisation and Reproducibility

The source tree separates controllers, models, services, routes, database migrations, views and tests in Laravel. The Python service separates API code, feature generation, training, model metadata and tests. Composer and npm lockfiles and Python requirement files record dependencies. The README and documentation provide setup, testing and deployment information. Environment files, datasets and generated model artifacts are excluded from version control. The ATUS source files are public but are not stored in the repository; a model artifact must be regenerated or transferred securely for a reproducible deployment.

The local project log is ignored by Git and therefore does not form part of the submitted commit history. It was used to maintain a concise record of work across issues. <mark>[PLACEHOLDER: State whether the final submission includes an AI-use log or other required project log as an appendix.]</mark>

#### 5.5.7 Hosting and Deployment

The system was deployed for demonstration to the Azure VM described in Section 5.2. A user-reported recommender health check confirmed that the slot model was loaded, and the application was served by Nginx from the Laravel public directory. This is evidence of a demonstration deployment at the time of the check. It does not establish current availability, HTTPS, production email delivery, Google Calendar OAuth on a final domain, verified NSG rules or deployment of the commitment-risk model. The risk model remained disabled in tracked deployment configuration.

<mark>[PLACEHOLDER: Insert a redacted screenshot showing the final demo URL and HTTPS status, and a matching Azure/recommender health check with capture date.]</mark>

*Figure 5.12: Azure demonstration deployment evidence.*

### 5.6 Author Oversight and Use of AI-Assisted Tools

OpenAI Codex assisted with repository inspection, collation of implementation and test evidence, drafting and language refinement. The project author retained responsibility for reviewing the source, checking reported numbers against repository records, deciding which claims were supported, and approving the final implementation and text. This chapter does not treat generated text as independent evidence; numerical results are tied to project records or explicitly attributed to user-provided evaluation results.

<mark>[PLACEHOLDER: Confirm the AI-use disclosure against the university's current policy and state whether a separate AI-use log was maintained.]</mark>

### 5.7 Evaluation Results, Discussion and Limitations

#### 5.7.1 Objective-by-Objective Evaluation

| Chapter One objective | Evidence in this project | Evaluation |
|---|---|---|
| Analyse challenges in schedule management, task fragmentation and cognitive workload | Literature review in Chapter Two examines disjointed calendar, task and routine-management tools and their limitations. | Addressed through the literature review and requirements analysis. The project did not independently measure population-wide workload. |
| Review existing digital scheduling and lifestyle applications and identify limitations | Chapter Two compares representative calendar, task and habit applications and identifies the integration and personalization gap. | Addressed within the scope of the reviewed applications and sources. |
| Develop an integrated web scheduling system using Random Forest slot suitability and rule-based conflict detection | Laravel workflows, the private Flask slot recommender, schedule occurrence logic, conflict checker and user-controlled confirmation were implemented and covered by automated tests. | Implemented. The recommender improves on the recorded baselines; its held-out test F1 of 0.7510 narrowly meets the stated target greater than 0.75. |
| Test performance, predictive accuracy, usability and algorithmic efficiency in scheduling | Automated application and Python tests, held-out model metrics, local sequential HTTP benchmark and user-reported UAT/SUS summary are available. | Partially supported by the available evidence. The local page benchmark met its target; mean SUS was reported as 78.0; the UAT aggregate needs reconciliation; held-out test F1 narrowly met its target. The work did not measure causal reduction in conflicts or improved real-world productivity. |

The Sprint 8 commitment-risk work is an additional extension beyond the original Chapter Four functional requirements. Its occurrence-outcome schema, Wasaa adaptation of the upstream logistic-regression and Platt-calibration model, preparation pipeline, private API boundary, Laravel integration, alert safeguards and tests are present in the repository. The upstream repository's synthetic booking rows are not used as Wasaa training data. The fitted Wasaa artifact and user-facing risk prediction remain disabled because an approved real dataset and acceptable held-out evaluation have not been established. It is therefore not concluded that the system currently predicts the actual behavior of Wasaa users.

#### 5.7.2 Discussion of Results

The implementation demonstrates that schedule management, activity tracking, conflict handling and machine-learning recommendations can be combined in one web application while retaining a separation between probabilistic ranking and deterministic schedule rules. Users retain control over whether to accept a recommendation or confirm an overlap. This design reduces the chance that a model score alone can silently rewrite a user's schedule.

The Random Forest outperformed the two validation baselines on F1, Hit Rate@3 and MAE. At the validation-selected threshold of 0.3823, it achieved held-out test F1 0.7510, narrowly above the proposal's greater-than-0.75 criterion. The labels are constructed proxies and the source data describes one day of U.S. respondents. These facts restrict the result to the prepared dataset; they do not establish personalization or improved scheduling outcomes for Kenyan users.

The performance test met the two-second target for the sampled local HTML requests. Because it used one client on the development host, it does not characterize concurrent load or Azure response times. The user-provided SUS mean of 78.0 exceeded the stated benchmark for the ten scored participants. The UAT summary reported a 95 per cent pass rate, but the case table marks all twenty tests as passing. Until that discrepancy and session details are reconciled, the aggregate should be treated cautiously and not generalized beyond the reported sessions.

The commitment-risk extension reuses the upstream model implementation with a Wasaa-specific feature adapter, but no approved real dataset or trained Wasaa model artifact is included. Test fixtures validate code behavior and failure handling only. The system is not ready to make real-user risk claims from this evidence.

#### 5.7.3 Limitations

The slot model uses U.S. ATUS data and constructed negatives rather than explicit acceptance or rejection of recommended times. A single diary day per respondent limits the history available to represent recurring behavior. Although the held-out test F1 narrowly meets the stated target, results may not generalize to Wasaa users or Kenyan students.

The adapted commitment-risk model has no approved Wasaa dataset, real-data baseline or held-out evaluation in the repository. The upstream synthetic bookings and incompatible `bookingId` lookup are not used as Wasaa model inputs. The risk service and alerts are disabled by default. Automated tests use synthetic data and mocks. Institutional approval for research use of personal scheduling history and deployment of an evaluated Wasaa model remain unresolved.

The user study is limited by the reported sample of ten SUS respondents and incomplete session metadata. The raw SUS item responses are unavailable for recalculation, and the UAT case table contradicts its summary. The local performance run was sequential and did not measure production or concurrent behavior. The Azure demonstration used an HTTP public IP at the time described; HTTPS, production SMTP, final-domain OAuth, VM network rules, backups and deployment of the Sprint 8 risk service were not independently verified. The Google Calendar importer is read-only and does not support all-day or cross-day events. No independent penetration test or formal external security audit was conducted.

### References to Project Evidence

Implementation statements in this chapter are based on the WasaaLifestyle source repository and its associated documentation: `docs/system-architecture.md`, `docs/azure-vm-deployment.md`, `docs/performance-benchmark.md`, `docs/uat-sus-test-plan.md`, `docs/commitment-risk-e2e-checks.md`, `docs/production-security.md`, the CRISP-DM reports in `ml/slot-recommender/docs/`, the Laravel migrations and test suite, and the model metadata in `ml/slot-recommender/models/`. The chapter follows the objectives and requirement catalogue in the approved project proposal and the supplied Chapter 5 writing guidance.

U.S. Bureau of Labor Statistics. (2024). *American Time Use Survey—2023 Microdata Files*. https://www.bls.gov/tus/data/datafiles-2023.htm

# CHAPTER SIX
## CONCLUSIONS, RECOMMENDATIONS AND FUTURE WORK

### 6.1 Introduction

This chapter concludes the study by relating its findings to the problem, objectives and evaluation reported in the preceding chapters. It presents conclusions supported by the available implementation and test evidence, recommendations arising from the measured limitations, and future work that remains beyond the completed project. It introduces no new experiment or performance result.

### 6.2 Conclusions

The project addressed the difficulty of coordinating schedules, tasks and lifestyle routines across separate tools. It delivered WasaaLifestyle OS as a web-based application that combines user and schedule management, activity tracking, conflict detection, time-slot recommendations, notifications and optional read-only calendar import. The system places schedule ownership and conflict resolution in Laravel and uses a separate Python service to rank candidate time slots. This keeps model recommendations advisory and leaves schedule changes to the user.

The first objective, to analyse challenges in schedule management, task fragmentation and cognitive workload, was addressed through the literature review and requirements analysis. The project did not conduct a population-scale workload study, so the findings describe the selected literature and system context rather than a measured prevalence of these challenges.

The second objective, to review existing digital scheduling and lifestyle applications, was addressed through the comparison in Chapter Two. The review informed an integrated design combining schedule management, routine tracking, recommendations and conflict checks. The conclusion is limited to the applications and sources examined in the proposal.

The third objective, to develop a web-based system using a Random Forest slot recommender and a rule-based conflict engine, was achieved in the repository implementation. The Laravel application supports account management, routines, schedules and recurrence, day and week views, notifications, administrator functions, and conflict-aware recommendation acceptance. The Flask recommender returns ranked time slots; the Laravel conflict checker independently tests overlaps and requires user confirmation. Automated testing covered the principal application and recommender contracts.

On the prepared ATUS data, the Random Forest achieved validation F1 0.7442, Hit Rate@3 0.3612 and MAE 3.682 hours. At the threshold selected on validation, held-out test F1 was 0.7510, narrowly meeting the proposal's greater-than-0.75 target; test Hit Rate@3 was 0.3675 and MAE 3.597 hours. Since suitability negatives are constructed from time-use records, these findings support comparative performance on the project's proxy dataset and do not prove that users would accept or complete the recommended slots.

The fourth objective, to evaluate performance, predictive accuracy, usability and algorithmic efficiency, was addressed using automated tests, model validation, local HTTP timing and reported participant evaluation. The Laravel suite recorded 230 passed and four skipped tests; the Python recommender suite recorded 107 passed; and the updated no-show pipeline suite recorded 12 passed locally. The local benchmark recorded 270 successful route responses under two seconds. Ten user-provided SUS scores averaged 78.0, above the target of 68. The user-reported UAT summary was 19 of 20 cases (95 per cent), but the individual table marks all cases as passing, so the detailed acceptance result requires correction. These results do not show causal reductions in schedule conflicts or improvements in productivity.

The later commitment-risk extension adds occurrence-outcome capture, leakage-limited feature preparation, an adaptation of the upstream calibrated logistic-regression model, a chronological evaluation pipeline, a private prediction endpoint, Laravel assessment integration, opt-in alert logic and tests. However, there is no approved real Wasaa dataset, real-data performance evaluation or trained Wasaa model artifact in the repository. Consequently, the extension is concluded to be an implemented but inactive prediction pathway, not a verified user-facing predictor. No real-user predictive accuracy or effectiveness of risk alerts is claimed.

Overall, the project demonstrates an integrated smart-scheduling prototype with a tested application and a comparative slot-ranking model. Its evidence supports the system's implementation and measured prototype behavior within the specified environments. Model generalizability, user impact, the unresolved UAT detail, HTTPS/email production readiness, concurrent load and real-data commitment-risk performance remain outside the conclusions justified by the current record.

### 6.3 Recommendations

The recommendations below follow from the limitations and results in Chapter Five.

| Priority | Audience | Recommendation | Evidence basis |
|---|---|---|---|
| Essential | Project author | Reconcile the UAT case table with the reported 19/20 result, identify the failed case, and attach the actual session date, tested environment and commit. | The current case table marks 20/20 Pass while the summary states 19/20. |
| Essential | Project author and supervisor | Complete the participant-study record with the required consent/approval status, valid SUS response count and anonymized source responses or approved summary. | Ten scores and a mean are available, but raw item answers and study metadata are not. |
| Essential before real users | System maintainer | Configure HTTPS, secure cookies, production SMTP, exact Google OAuth callback and tested backups before inviting users to enter real account data. | The Azure demonstration was accessed through HTTP; inbox delivery, final-domain OAuth and recovery readiness were not confirmed. |
| Essential | System maintainer | Keep Flask services on loopback or a private network, keep retraining private, and keep commitment-risk flags off until an approved-real artifact and reviewed threshold are available. | The APIs rely on network isolation; no approved real risk model or evaluation is present. |
| High | Researcher and supervisor | Obtain any required institutional approval before preparing or evaluating real user-history data, and record a data-retention and consent process. | No real Wasaa training dataset exists and approval requirements remain to be confirmed. |
| High | ML developer | Evaluate the slot model on appropriately approved local user data with user-level separation and direct recommendation acceptance/outcome labels. | Current ATUS data is U.S.-based, one-day and proxy-labeled; held-out test F1 only narrowly meets the target and does not establish local-user performance. |
| High | System maintainer | Perform a concurrent-load and deployed-server performance test with recorded hardware, dataset and workload details. | Existing timing evidence is a local sequential benchmark. |
| High | Project author | Capture final GitHub branch, commit, issue, pull-request and CI evidence on the actual thesis submission date. | Repository screenshots and live status are time-sensitive and cannot be inferred from a historical checkout. |

### 6.4 Future Work

Future work should extend the prototype based on evidence gaps rather than introduce model complexity without justification.

| Future direction | Rationale | Required work | Evaluation method |
|---|---|---|---|
| Localized slot-recommendation data | ATUS represents U.S. respondents and uses constructed labels; current F1 is below target. | Obtain consented and approved Wasaa usage data or an appropriate local dataset; define acceptance and completion outcomes. | User-level held-out evaluation reporting F1, Hit Rate@3, MAE and per-category performance against existing baselines. |
| Real commitment-risk model evaluation | The risk pipeline is implemented but has no approved real data or held-out metrics. | Secure approvals, collect explicit occurrence outcomes, prepare the private dataset, compare a baseline and candidate model, and review calibration and alert threshold. | Chronological held-out evaluation, threshold-specific precision/recall, calibration and alert-volume analysis; never use synthetic test metrics as user evidence. |
| Recommendation impact study | Offline ranking metrics do not show whether suggestions reduce conflicts or help users complete activities. | Conduct an approved pilot with suitable comparison or repeated-measures design. | Measure suggestion acceptance, completed activities, conflict frequency, replanning and SUS over a defined study period. |
| Usability study with complete records | The reported SUS average exceeds the benchmark, but raw items, detailed case reconciliation and session metadata are missing. | Conduct or document approved sessions with anonymized task outcomes and the standard ten SUS items. | Report valid sample, mean, range, item-level scoring, task completion and observed usability issues. |
| Calibration and explanations | The Random Forest's confidence values are not evidence of calibrated probabilities or causal feature effects. | Evaluate calibration on an appropriate held-out dataset and test understandable explanations. | Calibration curves and user comprehension tests; compare confidence with observed acceptance. |
| Broader calendar support | The importer is read-only and excludes all-day and cross-day events. | Add tested support for additional event types and providers with explicit user consent. | Import coverage, duplicate handling and conflict correctness tests. |
| Production reliability and scale | The demo is a single VM and the recorded performance workload is sequential. | Configure HTTPS, mail delivery, backup/restore, monitoring and concurrent-load controls; separate services if justified. | Security checks, restore drills, failure recovery and latency percentiles under defined concurrent workloads. |
| Mobile and notification access | The current product is a responsive web application and does not send push notifications. | Evaluate mobile-browser usability and, only if justified, a native client or opt-in push channel. | Device-specific task completion, accessibility and notification-delivery evaluation. |

The most important next research step is to validate the recommendation and commitment-risk components with appropriate, approved user data and complete evaluation records. Until then, WasaaLifestyle should be presented as a functional scheduling prototype with a validated slot-model comparison on proxy data and an implemented but inactive commitment-risk pathway.

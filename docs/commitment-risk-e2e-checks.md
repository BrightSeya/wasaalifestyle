# Commitment risk end-to-end verification

This record separates automated command results from browser checks. Automated tests use synthetic fixtures and mocked model responses; they do not demonstrate model quality or run predictions for real Wasaa users.

## Automated test record

| Area | Command | Result |
|---|---|---|
| Laravel application and integration tests | From `web`: `php artisan test` | Pending final run for this branch |
| Python recommender and commitment-risk API tests | From `ml/slot-recommender`: `python -m pytest` (Windows venv command below) | 107 passed |
| No-show data preparation and training contract tests | From `ml/slot-recommender`: `python -m pytest ..\no-show\tests` (Windows venv command below) | 11 passed |

Run commands from the repository root in PowerShell:

```powershell
Set-Location ml/slot-recommender
.\.venv\Scripts\python.exe -m pytest
.\.venv\Scripts\python.exe -m pytest ..\no-show\tests
Set-Location ..\..\web
php artisan test
```

Python environment used: Python 3.11.9, pytest 9.1.1, Windows. The API suite includes valid prediction response checks, unavailable/disabled model checks, invalid and missing fields, unexpected identifiers, and safe inference failures. Laravel tests cover owner scoping, no history, disabled/unavailable service, outcome eligibility, alert thresholds/timing/deduplication/opt-out/dismissal, alternative conflicts and confirmation-time changes.

Executed on 2026-11-12: Laravel reported **230 passed, 4 skipped (979 assertions)**; Python recommender/API reported **107 passed**; no-show pipeline reported **11 passed**. The four Laravel skips are live slot-recommender integration tests because the Flask service was not running. Results use synthetic fixtures and test doubles.

**Commit under test:** `26761d3` is the `origin/main` base. The issue #116 changes were tested in the uncommitted working tree on branch `116-test-commitment-risk-e2e`; record the PR head SHA after creating the local commit.

The Flask endpoint uses a synthetic test model in its test client, while Laravel uses HTTP fakes. No trained Wasaa commitment-risk artifact is available or enabled, and no live Python-to-Laravel request or real-user prediction was performed.

## Manual browser checks

Run these separately from the automated tests, in a local or dedicated test environment with a test account and synthetic test records. Do not enable predictions on Azure or another production environment for this checklist. The current Laravel risk flag is off by default, the alert threshold is unset, and the no-show model is disabled by default. Generating a real risk alert requires an approved model and a threshold supported by its evaluation.

For a browser-only UI smoke check, seed a synthetic alert in the local test database. In `web`, open Tinker with `php artisan tinker` and run the following, replacing the email with your local test account:

First make sure `.env` is configured for your local development database, then start the Laravel and frontend development servers in separate PowerShell windows from `web`:

```powershell
php artisan serve
npm run dev
```

Use a verified local test account. Do not point these steps at the Azure/production database.

```php
$user = App\Models\User::where('email', 'YOUR_LOCAL_TEST_EMAIL')->firstOrFail();
$start = now()->addDay()->startOfDay()->setTime(13, 0);
$schedule = $user->schedules()->create([
    'title' => 'Manual test commitment',
    'category' => 'work',
    'start_datetime' => $start,
    'end_datetime' => $start->copy()->addHour(),
    'priority' => 'medium',
    'status' => 'pending',
]);
$assessment = $schedule->riskAssessments()->create([
    'occurrence_start' => $start,
    'probability' => 0.8,
    'model_version' => 'manual-test-only',
    'assessed_at' => now(),
    'schedule_updated_at' => $schedule->updated_at,
]);
$user->notifications()->create([
    'schedule_id' => $schedule->id,
    'risk_assessment_id' => $assessment->id,
    'type' => 'system_message',
    'message' => 'Synthetic manual-test alert. This is not a prediction.',
    'is_read' => false,
]);
```

This fixture is only for checking alert display and dismissal. It does not exercise the scheduler or model. Requesting alternatives calls the slot recommender; it requires that separate service to be running with its own test data/model.

| Check | Steps | Expected result | Result / date / tester |
|---|---|---|---|
| Alert display | Sign in as the local test account and open Notifications. | The synthetic alert is visible with the commitment link and `Find a better time` and `Dismiss` actions. | Pending |
| Dismissal | Choose `Dismiss`, then refresh Notifications. | Alert disappears; the original schedule entry remains unchanged. | Pending |
| Alternative request | With the slot recommender running, choose `Find a better time`. | Recommendations page shows the commitment, confidence, available suitability signal, and explicit `Move to this time` / `Dismiss` actions. | Pending |
| Conflict recheck | Request suggestions, create another commitment overlapping one suggestion, then choose that suggestion. | The app reports the conflict, disables that move action, and leaves the original commitment unchanged. | Pending |
| Confirmation | Choose a conflict-free alternative and confirm. | A one-off commitment moves only after the explicit action. For a recurring commitment, only the selected occurrence is cancelled and replaced with a one-off entry. | Pending |
| Decline | Dismiss a suggestion without accepting it. | The original commitment remains unchanged. | Pending |
| Owner isolation | Sign in as a different test account and try to open or dismiss the first account's alert. | The other account cannot see or act on the alert. | Pending |

Record manual outcomes here only after performing them in the browser. These results are separate from automated command-line test results.

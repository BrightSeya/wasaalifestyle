# WasaaLifestyle HTTP Performance Check

**Issue:** #91 — `test: measure performance against the 2-second target`
**Status:** Local sequential baseline recorded. This does not establish production or concurrent-load performance.

## Target and scope

The project requirement is that normal application requests respond in under two seconds. This check measures full HTML responses from the Laravel application over HTTP, including Laravel, database access, middleware, and response transfer on the test machine.

It samples the public landing and login pages, plus authenticated dashboard, notification, activity, schedule, recommendation-list, and settings pages. It does not submit forms or modify account data. The credentials are requested interactively and are not written to the output.

The existing Flask `/predict` timing figure is a service-only measurement and explicitly excludes Laravel, browser rendering, and remote network time. Keep that evidence separate from this application-level result.

## Repeatable method

- Environment: local Windows development host, Laravel served at `http://127.0.0.1:8000`, MySQL running, and one verified active test account with synthetic data.
- Dataset: record the account's approximate schedule, activity, recommendation, and notification counts. Use a stable dataset when comparing runs.
- Workload: three warm-up requests followed by 30 sequential requests per page, one client at a time. The script fully reads each HTML response and records status, elapsed milliseconds, and response size.
- Summary: report median, nearest-rank 95th percentile, maximum, and count per route. A measured run meets the target only if every sampled normal page response is under 2,000 ms and each request succeeds with HTTP 200.
- Limitations: a local single-client run does not establish production latency, browser paint time, remote-network performance, or behavior under concurrent load. Record these limits in the project report.

## Run it

Start MySQL first. In PowerShell window 1, start Laravel from the `web` directory:

```powershell
cd C:\Users\brigh\OneDrive\Desktop\ISPROJECT26\wasaalifestyle\web
php artisan serve --host=127.0.0.1 --port=8000
```

Keep that window running. In PowerShell window 2, run the benchmark from the repository root:

```powershell
cd C:\Users\brigh\OneDrive\Desktop\ISPROJECT26\wasaalifestyle
python web\scripts\benchmark_http.py --base-url http://127.0.0.1:8000 --warmups 3 --samples 30
```

Enter the email and password for a verified active test account when prompted. The password is not echoed or saved. The CSV is written under `web\storage\app\performance\`, which Laravel keeps out of Git by default.

If the database schema is behind, run `php artisan migrate` from `web` before starting Laravel. Avoid `php artisan migrate --seed` on an already populated database; the default test-user email may already exist.

## Recorded baseline

- **Date/time and timezone:** 2026-11-08 14:26 Africa/Nairobi (from the CSV filename)
- **Commit tested:** `e4022f0` (merged #90 baseline)
- **Host / CPU / RAM:** Local Windows development machine; CPU and RAM were not captured for this run.
- **PHP and Laravel versions:** PHP 8.2.12; Laravel 12.69.3.
- **Database engine/version:** MySQL; exact server version was not captured.
- **App environment and debug setting:** Laravel's local `artisan serve` at `127.0.0.1:8000`; `APP_ENV` and debug setting were not captured.
- **Data volume:** A verified test account was used; record counts were not captured.
- **Warm-ups / samples / concurrency:** 3 warm-ups and 30 samples per route; concurrency 1.
- **Raw CSV path:** `web/storage/app/performance/http-benchmark-20261108-142643.csv` (local ignored output; not committed).
- **Routes at or above 2,000 ms:** None.
- **HTTP errors or redirects in the successful run:** None; all 270 measured responses returned HTTP 200.
- **Bottlenecks and practical fixes:** None indicated by this baseline; the slowest route maximum was 461.5 ms.
- **Rerun results:** The successful run itself was the rerun after an initial startup failure; no application optimization was indicated, so no post-fix run was needed.
- **Conclusion and limitations:** All 270 sampled responses were below 2,000 ms. This meets the target for these routes on this local, single-client run only. It does not establish deployed production latency or concurrent-load behavior.

| Route | Status | Samples | Median (ms) | P95 (ms) | Maximum (ms) |
| --- | --- | ---: | ---: | ---: | ---: |
| `/` | 200 | 30 | 285.1 | 321.1 | 324.5 |
| `/login` | 200 | 30 | 272.3 | 303.1 | 305.4 |
| `/dashboard` | 200 | 30 | 297.7 | 314.3 | 316.5 |
| `/notifications` | 200 | 30 | 292.8 | 369.9 | 377.3 |
| `/activities` | 200 | 30 | 335.3 | 369.5 | 386.3 |
| `/schedules` | 200 | 30 | 333.7 | 374.9 | 376.9 |
| `/schedules/week` | 200 | 30 | 332.4 | 361.3 | 362.0 |
| `/recommendations` | 200 | 30 | 333.3 | 356.7 | 386.8 |
| `/settings` | 200 | 30 | 330.4 | 401.3 | 461.5 |

Across all 270 samples, the median was 316.3 ms, the nearest-rank P95 was 369.5 ms, and the maximum was 461.5 ms.

### Initial failed attempt

The first attempt stopped at `GET /` with HTTP 500. The Laravel log records a database `QueryException` with SQLSTATE `HY000` / driver code `2002` (connection refused). That attempt produced no benchmark samples and is excluded from the baseline. The subsequent run completed all routes successfully.

The output is a measurement of the recorded local environment. Do not generalize it to a deployed production instance without measuring that instance separately.

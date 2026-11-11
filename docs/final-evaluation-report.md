# Wasaa Lifestyle final evaluation report

**Issue:** #95 - `docs: write the final evaluation report`
**Status:** Draft. The user confirms the supplied UAT/SUS figures are from collected sessions. Test metadata, a UAT count discrepancy, and the no-show model's version/provenance still need reconciliation.

## Executive summary

Wasaa Lifestyle combines a Laravel scheduling application with a Flask time-slot recommender. The application supports schedules, conflict review, activity tracking, recommendations, notifications, account settings, and an optional read-only Google Calendar integration. The deployed Azure demo has served the application over its public IP, and the recommender health endpoint has reported that its model is loaded.

The recorded local HTTP benchmark met the under-two-second target for all 270 measured page responses. The Random Forest improved top-three hit rate and mean absolute error over the recorded baselines, but its validation F1 of 0.7246 did not meet the project's target of greater than 0.75. User-supplied UAT results report 19 of 20 cases passing, and ten supplied participant SUS scores average 78.0, above the project's target of greater than 68. The UAT table conflicts with its summary by marking all 20 cases as passing; the test date, environment, and commit are also not filled in. The Azure IP demo uses HTTP; it is not suitable for password entry or public account use until HTTPS and production email delivery are configured and verified.

## Evaluation scope and evidence

This report uses evidence already recorded in the repository and project log. It does not claim an independent security audit, production load test, or completed user study.

| Area | Evidence | Result |
| --- | --- | --- |
| Application behavior | Laravel automated test suite recorded after Sprint 7 security and UI work | 189 passed, 1 skipped (748 assertions) at the last recorded full-suite run. |
| Recommender behavior | Slot-recommender pytest suite recorded after dependency remediation | 90 passed. |
| Frontend assets | Vite production build | Passed on the local Node 24.20.0 environment; CI is configured to build with Node 22.12.0. |
| Application response time | 30 sequential samples on each of nine Laravel routes | All 270 responses returned HTTP 200 and were under 2,000 ms. Combined median 316.3 ms, P95 369.5 ms, maximum 461.5 ms. |
| Recommendation model | Validation metrics in `ml/slot-recommender/models/baselines.json` | Random Forest validation F1 0.7246; Hit Rate@3 0.3612; MAE 3.682 hours. |
| User acceptance and usability | User-supplied Chapter 5 evaluation results; `docs/uat-sus-test-plan.md` | User confirms collected results: 20 UAT cases, summary 19/20 (95%), and 10 SUS scores averaging 78.0 (range 70.0-85.0). The case table marks all 20 Pass, so the UAT result needs reconciliation. Test date, environment, commit, raw SUS item responses, and study approval details are not recorded here. |
| Azure demo | User-reported browser access and VM recommender `/health` output | Application responds over the VM IP; recommender reported `model_loaded: true`. HTTPS, SMTP delivery, and Google OAuth on the final public HTTPS URL remain unverified. |

### Application performance

The local sequential benchmark sampled `/`, `/login`, `/dashboard`, `/notifications`, `/activities`, `/schedules`, `/schedules/week`, `/recommendations`, and `/settings`. Every measured response was below 2,000 ms. The slowest recorded maximum was `/settings` at 461.5 ms.

This is a local single-client result against Laravel and MySQL. It does not establish browser paint time, remote-network performance, Azure response time, or behavior under concurrent load. The tested commit was `e4022f0`; the baseline details and per-route results are in [`performance-benchmark.md`](performance-benchmark.md).

### Recommendation model

The Random Forest outperformed the completion-rate-only and logistic-regression baselines on the recorded validation data:

| Model | Validation F1 | Hit Rate@3 | MAE (hours) |
| --- | ---: | ---: | ---: |
| Completion-rate-only baseline | 0.6489 | 0.2757 | 4.655 |
| Logistic regression | 0.6354 | 0.2703 | 4.711 |
| Random Forest | 0.7246 | 0.3612 | 3.682 |

The Random Forest did not meet the stated F1 target of greater than 0.75. Its cross-validation F1 of 0.7299 is a separate metric and is not a held-out test result. The model uses U.S. American Time Use Survey data; its proxy suitability labels and population differences limit conclusions about real Wasaa users, including users in Kenya. See [`system-architecture.md`](system-architecture.md) and the model documentation for details.

### UAT and usability

The user confirms that the supplied participant evaluation figures are actual collected results. The supplied chapter reports ten participants and these anonymized SUS scores:

| Participant | SUS score |
| --- | ---: |
| P01 | 80.0 |
| P02 | 75.0 |
| P03 | 82.5 |
| P04 | 72.5 |
| P05 | 85.0 |
| P06 | 77.5 |
| P07 | 70.0 |
| P08 | 80.0 |
| P09 | 75.0 |
| P10 | 82.5 |

The supplied scores total 780 and average 78.0, with a range of 70.0-85.0. This exceeds the project's target of a mean above 68. The raw answers to the ten SUS questions were not supplied, so the individual scores cannot be independently recalculated. The session date, tested URL/environment, application version/commit, valid-response count, and consent/approval status are also still missing.

The supplied UAT summary reports 19 of 20 cases passing (95%), but its case table marks all 20 cases as Pass and does not identify a failed case. Keep the 95% result attributed to the supplied summary until the failure is identified and the case table corrected.

The chapter also reports no-show prediction cases and logistic-regression metrics (ROC-AUC 0.751, Brier score 0.136, and approximately 55% precision in the highest-risk 15%). The current repository contains only a no-show README and explicitly describes this service as planned, not implemented. These figures may describe another build or experiment; they are not treated as measured behavior of the current repository until the tested build, model, and evaluation method are identified.

Update this draft with the actual test date, URL/environment, application commit, failed UAT case, valid SUS response count, and available SUS item responses. Confirm the tested build for no-show scenarios and provide the evaluation artifact or method for those model metrics. Follow the proposal and supervisor's approved participant and ethics requirements.

## Security and deployment assessment

The repository includes account verification and throttling, authorization checks, production response headers, protected account deletion, dependency remediation, and documented production configuration. The recorded Laravel suite exercises application behavior, but no independent penetration test is claimed.

The Azure demo is reachable by IP over HTTP. This verifies basic deployment reachability but not a secure public release. Before inviting users to create accounts:

- Configure a stable hostname and valid HTTPS, then set `APP_URL` and secure session cookies to match.
- Configure and test production SMTP so verification and recovery messages reach inboxes; `MAIL_MAILER=log` is not delivery.
- Register the exact HTTPS Google Calendar callback URI and verify OAuth using the deployed hostname.
- Back up the database and verify restoration; monitor Azure credit and resource costs.

The detailed operational steps are in [`azure-vm-deployment.md`](azure-vm-deployment.md), and application-specific controls are in [`production-security.md`](production-security.md).

## Conclusions and follow-up

The recorded software tests and local response-time measurements support the technical feasibility of the application. The slot recommender improves on its recorded baselines, while its validation F1 target remains unmet. The supplied SUS scores indicate a mean of 78.0, above the target, while the supplied UAT result reports 95%; the UAT case discrepancy and missing test metadata limit confidence in those conclusions. The no-show metrics do not match the current repository's implemented scope. Production-safe public access also remains outstanding because HTTPS, production email, and the final Google OAuth callback are not verified.

Before closing #95, reconcile the UAT count, add the missing test metadata, confirm the SUS response records, and identify the no-show model build or remove those claims from the final evaluation. Then update the conclusions to match the evidence and link any follow-up issues.

## References

- [`UAT and SUS test plan`](uat-sus-test-plan.md)
- [`HTTP performance benchmark`](performance-benchmark.md)
- [`System architecture and model limitations`](system-architecture.md)
- [`Azure VM deployment runbook`](azure-vm-deployment.md)
- [`Production security checklist`](production-security.md)

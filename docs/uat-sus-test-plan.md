# WasaaLifestyle UAT and SUS Test Plan

**Issue:** #90 — `test: run UAT and measure SUS`  
**Status:** Ready to conduct; no participant results have been collected.

## Purpose

Check whether intended users can complete WasaaLifestyle's main planning tasks, record issues they encounter, and measure perceived usability with the System Usability Scale (SUS). The project usability target is a mean SUS score greater than 68, as stated in the recommender business-understanding document.

This plan prepares the sessions; it does not claim that UAT has been run. Use the participant count and eligibility criteria approved for the project proposal or supervisor. Do not invent or change those criteria for convenience.

## Before sessions

- Use a stable local or staging deployment and record its URL, commit, and date in the results report.
- Confirm the database is migrated and the app, mail-independent test flows, Vite assets, and recommendation service are available. Do not rerun `migrate --seed` against a populated database; the default test user can cause a duplicate-email error.
- Prepare one verified, dedicated test account per participant. Use synthetic schedules and activity data only. Do not ask participants to share personal account passwords or use their real calendar data.
- Prepare one existing schedule entry in the test account so the conflict task has a known overlapping time. Record the date/time and time zone for the facilitator.
- Obtain any consent or institutional approval required by the proposal or supervisor. Explain that participation is voluntary, they may stop at any time, and the test evaluates the product rather than the participant.
- Assign anonymous IDs (P01, P02, …). Do not record names, email addresses, passwords, or unnecessary personal details in the results sheet.

## Session procedure

1. Give the participant the short product introduction and test-account credentials. Ask them to think aloud if comfortable; do not coach them toward a control.
2. Read each task below as written. Start timing when the participant begins and stop when they say they are done or abandon the task.
3. Record completion, time, assistance, errors, and brief observations. Mark a task blocked if a product defect prevents completion.
4. After all tasks, give the participant the unmodified SUS questionnaire linked under [References](#references). Ask them to answer immediately and independently, before discussing their experience.
5. Thank the participant, sign out, and reset or remove only the synthetic test data created for the session.

## Participant tasks

Use a fresh test account for each participant and provide the required starting state without demonstrating the interface.

| ID | Read this task to the participant | What to record |
| --- | --- | --- |
| T1 | “Sign in to your WasaaLifestyle account and find the page where you can review your week.” | Login success, navigation difficulty, assistance, time. |
| T2 | “Add a recurring activity that would help you with your routine. Choose the days and time that suit you.” | Activity saved, field errors, whether recurrence and schedule were understood, time. |
| T3 | “Add an appointment during the time of the existing event on your schedule. Tell me what you notice, then adjust your appointment to a free time.” | Whether the conflict is noticed and understood, whether the participant resolves it, any misleading feedback, time. |
| T4 | “Find a suggested time for the activity you added and add a suggestion to your schedule if you would like to use it.” | Recommendation found, understood, accepted or declined, time, assistance. |
| T5 | “Record that you completed one of your planned activities.” | Completion recorded and visible in the expected place, time. |
| T6 | “Switch the site to dark mode, reload the page, and tell me what happened to your preference.” | Toggle found, appearance changes, preference persists, any unreadable or unchanged area. |
| T7 | “Open your account settings and update your display name to the test name on your instruction card.” | Settings found, edit saved, confirmation understood, time. |

Do not ask participants to delete their account or connect a personal Google Calendar. If calendar import is in the approved UAT scope, use a separate test Google account with synthetic events and add a separate task for it.

## Observation sheet

Create one copy per participant. Use anonymous participant IDs only.

| Participant ID | Task | Result (complete / partial / blocked) | Time | Help needed? | Error or observation |
| --- | --- | --- | --- | --- | --- |
| P__ | T1 |  |  |  |  |
| P__ | T2 |  |  |  |  |
| P__ | T3 |  |  |  |  |
| P__ | T4 |  |  |  |  |
| P__ | T5 |  |  |  |  |
| P__ | T6 |  |  |  |  |
| P__ | T7 |  |  |  |  |

## SUS collection and scoring

Use the standard 10-item SUS questionnaire as published; do not rewrite its statements or response scale. Record each response as an integer from 1 to 5 in the order shown on the questionnaire. A response sheet is valid only when all ten items are answered.

For each participant:

1. For items 1, 3, 5, 7, and 9, subtract 1 from the response.
2. For items 2, 4, 6, 8, and 10, subtract the response from 5.
3. Add the ten contributions and multiply the total by 2.5. The result is that participant's SUS score from 0 to 100.

Report the number of valid responses and the mean SUS score. Also include the minimum and maximum, and report task completion and recurring usability problems separately. Compare the mean with the project's target of **greater than 68**. Do not describe the mean as proof of usability for all users; include the participant count and study limitations.

| Participant ID | I1 | I2 | I3 | I4 | I5 | I6 | I7 | I8 | I9 | I10 | SUS score |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| P__ |  |  |  |  |  |  |  |  |  |  |  |

**Score calculation:** `((I1-1) + (5-I2) + (I3-1) + (5-I4) + (I5-1) + (5-I6) + (I7-1) + (5-I8) + (I9-1) + (5-I10)) * 2.5`

## Results report template

- **Environment / URL:**
- **Commit tested:**
- **Session dates:**
- **Participants recruited / valid SUS responses:**
- **Mean SUS (range):**
- **Target (>68) met:**
- **Task completion summary:**
- **Critical blockers:**
- **Recurring usability issues and evidence:**
- **Limitations:**
- **Follow-up issues to create:**

## References

- Brooke, J. (1996). *SUS: A quick and dirty usability scale.* [Original paper and questionnaire](https://hci-studies.org/methods-and-measures/downloads/SUS_Brooke1996.pdf).
- Project usability target: [`ml/slot-recommender/docs/01_business_understanding.md`](../ml/slot-recommender/docs/01_business_understanding.md).

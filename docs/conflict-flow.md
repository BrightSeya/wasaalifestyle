# Recommendation Conflict Flow

This sequence reflects the implemented recommendation acceptance flow in `RecommendationController::accept`. The controller checks the suggested interval against one-off and recurring schedule occurrences before saving it.

```mermaid
sequenceDiagram
    autonumber
    actor User
    participant RC as RecommendationController
    participant R as Recommendation
    participant CC as ConflictChecker
    participant SO as ScheduleOccurrences
    participant S as Schedule
    participant CNS as ConflictNotificationService
    participant N as Notification

    User->>RC: POST /recommendations/{id}/accept (confirmed=false)
    RC->>R: Authorize and read pending recommendation
    R-->>RC: Suggested start, end, category, activity
    RC->>CC: check(user, suggested_start, suggested_end, medium)
    CC->>SO: between(user, candidate date range)
    SO-->>CC: One-off and recurring occurrences
    CC-->>RC: Conflicts, if any

    alt No conflicts found
        RC->>S: Create schedule entry for user
        RC->>R: Set status to accepted
        RC-->>User: Redirect to recommendations with success status
    else Conflicts found
        RC-->>User: Redirect with conflict messages
        User->>RC: GET /recommendations
        RC-->>User: Show warning and Confirm anyway action
        User->>RC: POST /recommendations/{id}/accept (confirmed=true)
        RC->>CC: Recheck the suggested interval
        CC->>SO: between(user, candidate date range)
        SO-->>CC: Current one-off and recurring occurrences
        CC-->>RC: Current conflicts

        alt Conflicts remain
            RC->>S: Create schedule entry for user
            RC->>CNS: Create conflict notification
            CNS->>N: Save conflict_alert linked to schedule
        else Conflict no longer exists
            RC->>S: Create schedule entry for user
        end

        RC->>R: Set status to accepted
        RC-->>User: Redirect to recommendations with success status
    end
```

Direct schedule creation and updates use the same check, warning, and recheck pattern through `ScheduleController`. When the user confirms an entry while conflicts remain, the app saves the entry and creates a linked `conflict_alert` notification.

# Laravel–Flask Integration Tests

The Laravel feature suite includes `SlotRecommenderIntegrationTest`, which calls the real Flask service without `Http::fake`. If Flask is not reachable at `SLOT_RECOMMENDER_URL`, the integration tests skip instead of failing. The regular web tests continue to use fakes in `RecommendationServiceTest`.

## Start Flask

Open a PowerShell window and start the slot recommender. For the health, prediction, and unauthorized-key checks, the retraining key is optional. To opt into a successful retraining check, set the key before starting Flask:

```powershell
$env:ML_RETRAIN_KEY = "local-integration-secret"
cd C:\Users\brigh\OneDrive\Desktop\ISPROJECT26\wasaalifestyle\ml\slot-recommender
.\.venv\Scripts\Activate.ps1
python app/api.py
```

Leave that window running. Flask needs the local trained model and completion-rate lookup artefacts to answer health and prediction requests.

## Run the integration tests

In a second PowerShell window, use the same key and run the focused Laravel test class:

```powershell
$env:SLOT_RECOMMENDER_URL = "http://127.0.0.1:5001"
$env:ML_RETRAIN_KEY = "local-integration-secret"
cd C:\Users\brigh\OneDrive\Desktop\ISPROJECT26\wasaalifestyle\web
php artisan test --filter=SlotRecommenderIntegrationTest
```

This exercises `/health`, `/predict` through `RecommendationService`, and missing/wrong `/retrain` keys against the real service. The valid-key retraining test is skipped by default so an ordinary suite run does not unexpectedly train the full model.

To explicitly run the valid-key retraining test, first ensure the Flask process was started with the matching `ML_RETRAIN_KEY`, then enable the opt-in in the Laravel test window:

```powershell
$env:RUN_ML_RETRAIN_INTEGRATION = "1"
php artisan test --filter=SlotRecommenderIntegrationTest
```

This opt-in runs the complete training pipeline (GroupKFold grid search), which can take a while and rewrites local model artefacts and model-card metrics.

## Run all Laravel tests

```powershell
cd C:\Users\brigh\OneDrive\Desktop\ISPROJECT26\wasaalifestyle\web
php artisan test
```

If Flask is stopped, the integration tests skip; all other Laravel tests run as usual using the in-memory SQLite test database.

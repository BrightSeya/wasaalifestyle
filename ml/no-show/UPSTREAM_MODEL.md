# Existing appointment model provenance

This document records an appointment-domain model explored during development. The running WasaaLifestyle app does not load or call this model. Its synthetic source data and held-out metrics do not establish performance on Wasaa personal activities. The implementation was removed from the live app; this folder is retained as historical research material only.

- Upstream repository: https://github.com/BrightSeya/scheduling
- Pinned upstream commit: `c40599197b054a5248461c0ff9a2532fd3e4da1c`
- Model implementation: `app/model.py`
- Feature implementation: `app/features.py`
- Dataset: `data/bookings.csv` (21,333 synthetic bookings)
- Bundled Wasaa copy: `ml/no-show/data/upstream/bookings.csv`
- SHA-256: `a6c0b949d20d988583bbda03b91e0b506dc8fe3f2a3b5fa1163d12388bc6d98a`

The source target `did_not_show` includes both `NO_SHOW` and `CANCELLED`; Wasaa's recorded `missed` outcome excludes cancellation. Source inputs for amount, provider rating, provider verification and service category do not have direct Wasaa schedule equivalents. The adapter uses upstream fit-set centers for unsupported numeric inputs. This experiment does not validate probability calibration or alert quality for Wasaa users. The API marks each prediction as prototype-only.

ATUS remains the source for Wasaa's separate time-slot recommendation model. It does not provide missed-commitment labels.

The Wasaa workflow records explicit occurrence outcomes and uses a chronological holdout to compare the current model, an adapted model and the rule-based fallback. New user history changes request features; model weights do not learn online between retraining runs. Slot-suitability prediction remains separate and unchanged.

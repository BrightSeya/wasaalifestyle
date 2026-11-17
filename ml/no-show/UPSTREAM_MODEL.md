# Upstream cold-start model provenance

Wasaa's no-show cold-start artifact is fitted from the synthetic bookings dataset in the user's local `BrightSeya/scheduling` repository. The artifact is reproduced from the upstream source code and data; no pre-trained weights are checked into either repository.

- Upstream repository: https://github.com/BrightSeya/scheduling
- Pinned upstream commit: `c40599197b054a5248461c0ff9a2532fd3e4da1c`
- Model implementation: `app/model.py`
- Feature implementation: `app/features.py`
- Dataset: `data/bookings.csv` (21,333 synthetic bookings)
- Bundled Wasaa copy: `ml/no-show/data/upstream/bookings.csv`
- SHA-256: `a6c0b949d20d988583bbda03b91e0b506dc8fe3f2a3b5fa1163d12388bc6d98a`

The source target `did_not_show` includes both `NO_SHOW` and `CANCELLED`; Wasaa's recorded `missed` outcome excludes cancellation. Source inputs for amount, provider rating, provider verification and service category do not have direct Wasaa schedule equivalents. The adapter uses upstream fit-set centers for unsupported numeric inputs and sends unmatched lifestyle categories as unknown. This permits a cold-start demonstration but does not validate probability calibration or alert quality for Wasaa users.

ATUS remains the source for Wasaa's separate time-slot recommendation model. It does not provide missed-commitment labels.

Wasaa's future model update uses explicit occurrence outcomes, a chronological holdout and comparison with the training-period baseline. It should replace the synthetic cold-start artifact only after the real-data report and held-out results are reviewed. New explicit user history changes the request features; model weights do not learn online between retraining runs.

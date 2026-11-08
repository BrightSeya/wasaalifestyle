# Wasaa Lifestyle OS

An ML-based smart scheduling system. A Random Forest model recommends the top-3 time slots
for tasks and lifestyle activities, and a rule-based engine detects scheduling conflicts.

## Structure
- `web/` - Laravel web application (Blade, Sanctum, MySQL)
- `ml/slot-recommender/` - Python Flask service for time-slot suitability (Random Forest)
- `ml/no-show/` - Python service for missed-activity prediction (planned)
- `docs/` - project documentation ([index](docs/README.md))

## Getting started
- Laravel setup: [`web/README.md`](web/README.md)
- Complete architecture, integration, configuration, testing, and model limitations: [`docs/system-architecture.md`](docs/system-architecture.md)
- End-user guide: [`docs/user-manual.md`](docs/user-manual.md)

## Methodology
- Web application: Agile (Scrum-inspired sprints)
- Machine learning: CRISP-DM

## Author
Bright Seya Ndinya (164994), Strathmore University. Supervisor: Dr. Deperias Kerre.
.env
.env.*
!.env.example

# Node
node_modules/

# Python
__pycache__/
*.py[cod]
.venv/
venv/
.pytest_cache/
.ipynb_checkpoints/

# ML artefacts (large and reproducible from scripts)
ml/**/data/raw/
ml/**/data/processed/
ml/**/models/*.joblib

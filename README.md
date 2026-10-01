# Wasaa Lifestyle OS

An ML-based smart scheduling system. A Random Forest model recommends the top-3 time slots
for tasks and lifestyle activities, and a rule-based engine detects scheduling conflicts.

## Structure
- `web/` - Laravel web application (Blade, Sanctum, MySQL)
- `ml/slot-recommender/` - Python Flask service for time-slot suitability (Random Forest)
- `ml/no-show/` - Python service for missed-activity prediction (planned)
- `docs/` - project documentation

## Methodology
- Web application: Agile (Scrum-inspired sprints)
- Machine learning: CRISP-DM

## Workflow
- Every change starts as an issue on the "Wasaa Lifestyle" project board.
- Branches: `feat/<issue>-<slug>`, `fix/...`, `docs/...`, `chore/...`, `test/...`
- Commits follow Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`, `test:`).
- Pull requests start with `Closes #<issue>` and merge only after CI passes.

## Author
Bright Seya Ndinya (164994), Strathmore University. Supervisor: Dr. Deperias Kerre.

# OS / editors
.DS_Store
Thumbs.db
.vscode/
.idea/

# Environment files
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

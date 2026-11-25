import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))

from upstream_cold_start import personalize_upstream_probability


def test_category_missed_history_moves_cold_start_estimate_toward_personal_rate():
    row = {
        "prior_same_category_labeled_count": 4,
        "prior_same_category_missed_rate": 1.0,
    }

    probability = personalize_upstream_probability(0.2, row)

    assert probability == pytest.approx(0.4666666666666667)


def test_no_category_history_preserves_the_cold_start_probability():
    row = {
        "prior_same_category_labeled_count": 0,
        "prior_same_category_missed_rate": None,
    }

    assert personalize_upstream_probability(0.2, row) == 0.2

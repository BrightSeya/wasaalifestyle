import pytest

from atus_category_map import CATEGORIES, TIER1_MAP, TIER2_OVERRIDES, map_activity_category


@pytest.mark.parametrize(
    ("tier1", "tier2", "expected"),
    [
        (5, 1, "work"),
        (6, 1, "work"),
        (13, 1, "exercise"),
        (12, 3, "leisure"),
        (11, 1, "leisure"),
        (2, 2, "errand"),
        (7, 1, "errand"),
        (12, 1, "social"),
        (16, 1, "social"),
        (1, 1, "rest"),
    ],
)
def test_maps_codes_to_expected_category(tier1, tier2, expected):
    assert map_activity_category(tier1, tier2) == expected


def test_tier2_override_takes_priority_over_tier1():
    assert map_activity_category(12) == "leisure"
    assert map_activity_category(12, 1) == "social"


def test_data_codes_are_excluded():
    assert map_activity_category(50, 1) is None


def test_unknown_tier1_code_is_excluded():
    assert map_activity_category(17) is None
    assert map_activity_category(99, 1) is None


def test_accepts_zero_padded_strings():
    assert map_activity_category("05", "01") == "work"
    assert map_activity_category("12", "01") == "social"


def test_every_category_is_reachable():
    produced = set(TIER1_MAP.values()) | set(TIER2_OVERRIDES.values())
    produced.discard(None)
    assert produced == set(CATEGORIES)


def test_categories_match_the_web_app():
    # Must equal App\Enums\Category in the Laravel app.
    assert CATEGORIES == ("work", "exercise", "leisure", "errand", "social", "rest")

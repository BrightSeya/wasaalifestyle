"""Map ATUS activity codes to the six WasaaLifestyle activity categories.

ATUS classifies every activity with a 6-digit lexicon code; the first two digits
are the tier-1 (major) category and the next two the tier-2 category.
Reference: BLS ATUS Activity Lexicon, https://www.bls.gov/tus/lexicons.htm
"""

CATEGORIES = ("work", "exercise", "leisure", "errand", "social", "rest")

TIER1_MAP = {
    "01": "rest",      # Personal care (sleep, grooming)
    "02": "errand",    # Household activities
    "03": "social",    # Caring for household members
    "04": "social",    # Caring for non-household members
    "05": "work",      # Work and work-related
    "06": "work",      # Education
    "07": "errand",    # Consumer purchases
    "08": "errand",    # Professional and personal care services
    "09": "errand",    # Household services
    "10": "errand",    # Government services and civic obligations
    "11": "leisure",   # Eating and drinking
    "12": "leisure",   # Socializing, relaxing and leisure (refined below)
    "13": "exercise",  # Sports, exercise and recreation
    "14": "social",    # Religious and spiritual
    "15": "social",    # Volunteer activities
    "16": "social",    # Telephone calls
    "18": "errand",    # Traveling
    "50": None,        # Data codes (unable to code) - excluded
}

# Tier-1 "12" mixes time with others and time alone, so split it by tier-2.
TIER2_OVERRIDES = {
    ("12", "01"): "social",   # Socializing and communicating
    ("12", "02"): "leisure",  # Attending or hosting social events
    ("12", "03"): "leisure",  # Relaxing and leisure
}


def map_activity_category(tier1code, tier2code=None):
    """Return the Wasaa category for an ATUS activity, or None if it should be excluded."""
    t1 = str(int(tier1code)).zfill(2)
    t2 = str(int(tier2code)).zfill(2) if tier2code is not None else None

    if t2 is not None and (t1, t2) in TIER2_OVERRIDES:
        return TIER2_OVERRIDES[(t1, t2)]

    return TIER1_MAP.get(t1)

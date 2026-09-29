# CRISP-DM Phase 2 — Data Understanding

Source analysis: [`notebooks/01_eda.ipynb`](../notebooks/01_eda.ipynb).

## 1. Data collected
**American Time Use Survey (ATUS) 2023**, U.S. Bureau of Labor Statistics. Each respondent records every activity
over one diary day (04:00 to 04:00).

| File | Rows | Used columns |
|---|---|---|
| `atusact_2023.dat` (activity) | 153,120 | `TUCASEID`, `TUSTARTTIM`, `TUACTDUR24`, `TUTIER1CODE`, `TUTIER2CODE` |
| `atusresp_2023.dat` (respondent) | 8,548 | `TUCASEID`, `TUDIARYDAY`, `TRHOLIDAY`, `TUFINLWGT` |

Every respondent has activities (8,548 respondent-days).

## 2. Data quality
- No nulls, negative "missing" codes, duplicate rows or zero durations in the used columns.
- 1,600 rows (1.04%) cannot be mapped to a Wasaa category (ATUS data code `50`) and are dropped.
- 139 diary days fall on a public holiday.

## 3. Data description

### Categories (after mapping, issue #8)
| Category | Rows | Share |
|---|---|---|
| errand | 57,743 | 38.1% |
| leisure | 38,858 | 25.6% |
| rest | 30,742 | 20.3% |
| social | 14,482 | 9.6% |
| work | 7,095 | 4.7% |
| exercise | 2,600 | 1.7% |

### Time of day
Peak start hour: exercise 08:00, work 12:00, errand 17:00, leisure 18:00, social 19:00, rest 04:00.
Categories follow distinct daily patterns, so **hour** is expected to be a strong feature.

### Day of week
| | Sun | Mon | Tue | Wed | Thu | Fri | Sat |
|---|---|---|---|---|---|---|---|
| Respondents | 2,148 | 904 | 914 | 833 | 866 | 849 | 2,034 |
| Raw share | 25.1% | 10.6% | 10.7% | 9.7% | 10.1% | 9.9% | 23.8% |
| Weighted share | 14.3% | 13.9% | 14.2% | 15.0% | 13.9% | 14.2% | 14.4% |

Work is about 7% of weekday rows but about 2% at weekends.

### Schedule density
Activities per respondent-day: mean 17.7, median 17, range 4–67.

## 4. Issues found and decisions for Data Preparation
| # | Finding | Decision |
|---|---|---|
| 1 | ATUS has only positive examples (activities people did) | Generate negative examples; document the label as a proxy |
| 2 | ~18 rows per respondent | Split train/validation/test **grouped by `TUCASEID`** to prevent leakage |
| 3 | Completion rate is a category × hour frequency | Compute it on the **training set only**, then apply to validation/test |
| 4 | ~43% of errand rows are travel (tier-1 `18`) | Evaluate excluding travel; justify the choice in Data Preparation |
| 5 | 26.2% of rest rows (8,057) "start" at exactly 04:00 because the diary begins then | Treat 04:00 sleep starts as artefacts, not chosen times |
| 6 | Weekends are 49% of respondents but 29% weighted | Keep day of week as a feature; note the oversampling when interpreting results |
| 7 | Exercise (1.7%) and work (4.7% of rows, 10.9% of minutes) are small | Report metrics per category as well as overall |

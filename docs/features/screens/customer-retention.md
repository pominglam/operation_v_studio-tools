# Customer retention

**Route:** `/reports/customer-retention` (nested under [Reports hub](reports.md))  
**Page:** `resources/js/pages/CustomerRetentionReportPage.vue`  
**Nav:** **Reports** → **Customer retention** (admin only)

## Purpose

Identified people built from the `shopify_orders` copy (same eligible rows as [Orders](shopify-orders.md) and [staff orders](staff-orders-report.md)). Each person is merged by Shopify customer GID, email, or phone (R1–R3). Email and phone are **not** shown.

## User actions

| Action | Behavior |
| --- | --- |
| Open page | Header cards + **People** / **Month by month** tabs. Filters and tab persist in `page_state:customer-retention:list:v3`. |
| Month by month tab | Chart.js combo (stacked New + Returning buyers; New / Returning / Total spend lines) plus the month table. Current Montreal month is faded. |
| People tab | Paginated identified people, newest last order first. |
| Search name | Case-insensitive match on Shopify display name only. |
| Our label | **New** (1), **Repeat** (2+), **Loyal** (3+). Repeat includes Loyal. |
| RFM group | One of Shopify’s 10 buyer groups (Prospects omitted). |
| Store rhythm | **On cadence**, **Due**, or **Lapsed** vs this store’s typical gap (includes 1-order people). |
| Own pace | **Churned** or **Active (2+)** — personal 2×-gap, kept for comparison. |
| Sort column | Name, label, RFM, orders, spend, AOV, last order, cadence, own pace, R/F/M. |
| Click RFM name | Popover with Shopify’s R + FM rule. |
| Click ⓘ | R/F/M, monthly Orders/Spend/AOV, people AOV, store rhythm, and own-pace formulas. |
| Click person name | Expands eligible orders (name, time, channel, subtotal) with Shopify Admin links. |

Header cards: identified people, repeat rate (2+), New / Repeat / Loyal, identified vs unidentified spend, identified AOV, On cadence / Due / Lapsed (store rhythm; own-pace churned count as a footnote).

### Month by month

Calendar months in **Eastern Time (Montreal)** — same clock as Toronto, so day/month buckets match the rest of the ERP.

Chart (above the table): stacked columns are the main read — **New** + **Returning** identified buyers (same colors as Mix). Lighter overlay lines are **New spend** (orange), **Returning spend** (violet), and **Total spend** (slate). Hover also shows return rate and AOV. The current calendar month is faded until it closes. The month table stays below with the same spend split.

| Column | Formula / meaning |
| --- | --- |
| New | First identified eligible order that month (acquisition) |
| Returning | Bought that month and already had an earlier identified order |
| Return rate | Returning ÷ buyers that month |
| Those new, now 2+ | Of that month’s New people, how many have 2+ orders as of today |
| Orders | Count of identified eligible orders in the month (not cancelled / not voided) |
| Spend | Σ `subtotal_shop_amount` for those orders (before tax and shipping) |
| New spend | Spend that month from people whose first eligible order is that month |
| Returning spend | Spend that month from people who already had an earlier-month order |
| AOV | Spend ÷ orders |

### People metrics

| Field | Formula |
| --- | --- |
| AOV | Person spend ÷ person orders |
| Identified AOV (card) | Identified spend ÷ identified orders |
| Store rhythm | Median of consecutive gaps ≥ 14 days. **Due** at 1× that median. **Lapsed** at `max(60, 2 × median)`. Anyone with a dated order is scored. |
| Own pace | Needs 2+ dated orders. Average gap = mean days between consecutive orders (minimum 1 day). Threshold = 2 × average gap. **Churned** when days since last order > threshold. |

## API

| Method | Path | Query / notes |
| --- | --- | --- |
| GET | `/api/v1/reports/customer-retention` | `search`, `frequency=new\|repeat\|loyal`, `rfm_group`, `cadence=on_cadence\|due\|lapsed`, `churn=active\|churned`, `sort_by`, `sort_dir`, `page`, `per_page` (max 100). Response `{ data, summary, meta }`. |
| GET | `/api/v1/reports/customer-retention/{personId}` | `personId` is sha256 of merged identity keys. `{ data, orders }`. **404** if missing. |

`summary` includes eligible/identified/unidentified counts and spend, New / Repeat / Loyal, `repeat_rate`, `churned_count` (own pace), `identified_aov`, `store_cadence` (`median_gap_days`, `due_after_days`, `lapsed_after_days`, `typical_gap_count`, `gap_floor_days`), `cadence_on_count` / `cadence_due_count` / `cadence_lapsed_count`, `rfm_counts`, `rfm_catalog`, and `months` (including monthly `aov`, `acquired_spend`, `returning_spend`). Each person includes `aov`, `cadence` (`status`, `days_since_last`, `due_after_days`, `lapsed_after_days`) or `cadence: null`, and `churn` (`status`, `avg_gap_days`, `days_since_last`, `threshold_days`) or `churn: null`.

## Backend

| Piece | Role |
| --- | --- |
| `CustomerRetentionSnapshotBuilder` | Eligible rows → merge → RFM + AOV + store rhythm + own-pace. |
| `CustomerIdentityMergeService` | Union-find on GID / email / phone. |
| `CustomerRfmScoringService` | Store quintiles + Shopify group names. |
| `CustomerStoreCadenceCalculator` | Median of 14+ day gaps; Due at 1×, Lapsed at `max(60, 2×)`. |
| `CustomerChurnCalculator` | 2× that person’s own average gap (comparison only). |
| `CustomerRetentionReportService` | Filter, sort, paginate. |
| `CustomerRetentionMonthlySeriesBuilder` | Eastern Time (Montreal) New / returning / orders / spend / AOV. |
| `shopify:customer-retention-crosscheck` | ERP invariants + API summary + monthly + AOV/own-pace + store cadence; `--live` pages Shopify Admin. |

Scoring note: RFM is computed on merged people, so Shopify Admin’s native `rfm_group` may differ when two profiles share an email or phone.

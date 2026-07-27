# Staff orders report

**Route:** `/reports/staff-orders`  
**Page:** `resources/js/pages/StaffOrdersReportPage.vue`  
**Nav:** **Reports** (admin only)

## Purpose

Monthly table of **eligible Shopify orders** grouped by **calendar day** (America/Toronto) and attribution bucket:

- Configured POS staff (`config/shopify.php` → `staff_order_report.staff`, keyed by Shopify REST `user_id`)
- **Quick Sale**, **Online Store**, **Shop**, **POS (other)**

Cancelled and voided orders are excluded (same rules as demand rollups).

## User actions

| Action | Behavior |
| --- | --- |
| Open page | Loads **current calendar month** by default. |
| **Previous** / **Next** | Moves one month at a time; refetches report. |
| Scroll table | One row per day in the month; footer row shows column totals. |

## API

| Method | Path | Query | Response |
| --- | --- | --- | --- |
| GET | `/api/v1/reports/staff-orders` | `month=YYYY-MM` (required) | `{ data: { month, timezone, columns[], rows[], totals, orders_scanned } }` |

- Results are cached briefly (`shopify.staff_order_report.cache_ttl_seconds`, default 300s).
- POS staff attribution uses a per-order Shopify REST lookup (`user_id`) when `sourceName` is `pos`.

## Backend services

| Service | Role |
| --- | --- |
| `ShopifyStaffOrdersMonthlyReportService` | Paginates Shopify Admin GraphQL orders for the month, classifies buckets, aggregates daily counts. |
| `ShopifyOrderStaffBucketClassifier` | Maps `sourceName` / channel / `user_id` → report column key. |
| `ShopifyOrderPosUserIdFetcher` | REST fetch of `user_id` for POS orders. |
| `ShopifyOrderDemandEligibility` | Skips cancelled / voided orders. |

## Config

`config/shopify.php` → `staff_order_report`:

- `timezone` — report day boundaries (default `America/Toronto`)
- `staff` — Shopify `user_id` → `{ key, label }`
- `extra_buckets` — non-staff columns (Quick Sale, Online Store, etc.)

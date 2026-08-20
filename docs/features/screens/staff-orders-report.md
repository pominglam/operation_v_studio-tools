# Staff orders report

**Route:** `/reports/staff-orders` (nested under [Reports hub](reports.md))  
**Page:** `resources/js/pages/StaffOrdersReportPage.vue`  
**Nav:** **Reports** → **Staff orders** (admin only)

## Purpose

Monthly table of **eligible Shopify orders** grouped by **calendar day** (America/Toronto) and attribution bucket:

- Configured POS staff (`config/shopify.php` → `staff_order_report.staff`, keyed by Shopify REST `user_id`)
- **Quick Sale**, **Online Store**, **Shop**, **POS (other)**

One table with a **Show** dropdown to switch between:

1. **Order counts** — number of eligible orders per day/bucket.
2. **Revenue before tax** — sum of Shopify `currentSubtotalPriceSet.shopMoney` per day/bucket (shop currency, typically CAD).

Cancelled and voided orders are excluded (same rules as demand rollups).

Data comes from the **`shopify_orders`** ERP mirror (same rows kept fresh by incremental order sync), not a live Shopify pull when the page loads.

## User actions

| Action | Behavior |
| --- | --- |
| Open page | Loads **current calendar month** by default; table shows **Revenue before tax**. |
| **Previous** / **Next** | Moves one month at a time; refetches report. |
| Month select | Jump to any month from December 2025 onward. |
| **Show** dropdown | Switches table between **Order counts** and **Revenue before tax**. |
| Scroll table | One row per calendar day in the month; footer row shows column totals for the active view. |

## API

| Method | Path | Query | Response |
| --- | --- | --- | --- |
| GET | `/api/v1/reports/staff-orders` | `month=YYYY-MM` (SPA default) or `from_month` + `to_month` for multi-month | `{ data: { from_month, to_month, month?, timezone, ... } }` |

- Reads **`shopify_orders`** only — fast after mirror is populated.
- `orders_missing_attribution` counts eligible orders in the month with null `source_name` (run backfill command below).
- `orders_missing_subtotal` counts eligible orders with null `subtotal_shop_amount` (re-run backfill or wait for incremental sync).
- Empty `rows` or `revenue_rows` from the API is treated as an error (report must return one row per calendar day).

## Backend services

| Service | Role |
| --- | --- |
| `ShopifyStaffOrdersMonthlyReportService` | Aggregates **`shopify_orders`** mirror rows for one calendar month. |
| `ShopifyOrderStaffAttributionUpsertService` | Persists `source_name`, `channel_name`, and POS `pos_user_id` during order sync upserts. |
| `ShopifyOrderStaffBucketClassifier` | Maps source/channel/`pos_user_id` → report column key. |
| `ShopifyOrderPosUserIdFetcher` | REST fetch of `user_id` for POS orders during sync/backfill only. |
| `ShopifyOrderDemandEligibility` | Skips cancelled / voided orders. |

## Mirror columns (`shopify_orders`)

- `source_name` — Shopify `sourceName` (e.g. `pos`, `web`, `quick_sale`)
- `channel_name` — Shopify channel label when present
- `pos_user_id` — Shopify REST `user_id` for POS orders (staff attribution)
- `subtotal_shop_amount` — order subtotal before tax in shop currency (`currentSubtotalPriceSet.shopMoney.amount`)

Populated on **incremental/historical order sync** and webhook upserts. Existing rows before deploy need a one-time backfill (also refreshes subtotals):

```powershell
php artisan shopify:orders-backfill-staff-attribution 2026-07
```

## Config

`config/shopify.php` → `staff_order_report`:

- `timezone` — report day boundaries (default `America/Toronto`)
- `staff` — Shopify `user_id` → `{ key, label }`
- `extra_buckets` — non-staff columns (Quick Sale, Online Store, etc.)

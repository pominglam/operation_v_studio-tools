# Staff orders report

**Route:** `/reports/staff-orders` (nested under [Reports hub](reports.md))  
**Page:** `resources/js/pages/StaffOrdersReportPage.vue`  
**Nav:** **Reports** → **Staff orders** (admin only)

## Purpose

Monthly table of **eligible Shopify orders** grouped by **calendar day** (America/Toronto) and attribution bucket:

- Configured POS staff (`config/shopify.php` → `staff_order_report.staff`, keyed by Shopify REST `user_id`)
- **Special order**, **Cash sale**, **NT sales**, **Quick Sale**, **Online Store**, **Shop**, **POS (other)**

**Special order** is exclusive: Shopify draft-order invoices (`source_name=shopify_draft_order`) and orders tagged `special-order` / `special-deposit` / `special-balance`.

**Cash sale** is exclusive (after special order): Shopify **Cash** payment gateway, or tag `cash`. These are real Shopify cash tenders, not ERP NT checkout.

**NT sales** is exclusive (after special order): tags `nt` / `nt-sale` / `nt_sales` / `nt-sales` only. An NT tag wins over a Cash gateway on the same order. ERP NT checkout is not live yet; when it is, those sales should join this column.

One table with a **Show** dropdown to switch between:

1. **Order counts** — number of eligible orders per day/bucket.
2. **Revenue before tax** — sum of Shopify `currentSubtotalPriceSet.shopMoney` per day/bucket (shop currency, typically CAD).

Cancelled and voided orders are excluded (same rules as demand rollups).

Data comes from the **`shopify_orders`** ERP mirror (same rows kept fresh by incremental order sync), not a live Shopify pull when the page loads. Individual orders are on **[Orders](shopify-orders.md)** (`/orders`).

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
| `ShopifyOrderStaffBucketClassifier` | Maps source/channel/`pos_user_id`/tags/payment gateways → report column key. Special order, Cash sale, and NT sales take priority over staff/channel. Changing staff or `extra_buckets` updates the report, Orders, and store events together. |
| `ShopifyOrderPosUserIdFetcher` | REST fetch of `user_id` for POS orders during sync/backfill only. |
| `ShopifyOrderDemandEligibility` | Skips cancelled / voided orders. |

## Mirror columns (`shopify_orders`)

- `source_name` — Shopify `sourceName` (e.g. `pos`, `web`, `quick_sale`)
- `channel_name` — Shopify channel label when present
- `pos_user_id` — Shopify REST `user_id` for POS orders (staff attribution)
- `payment_gateway_names` — Shopify `paymentGatewayNames` (Cash → **Cash sale**)
- `subtotal_shop_amount` — order subtotal before tax in shop currency (`currentSubtotalPriceSet.shopMoney.amount`)

Populated on **incremental/historical order sync** and webhook upserts. Existing rows before deploy need a one-time backfill (also refreshes subtotals):

```powershell
php artisan shopify:orders-backfill-staff-attribution 2026-07
```

## Config

`config/shopify.php` → `staff_order_report`:

- `timezone` — report day boundaries (default `America/Toronto`)
- `staff` — Shopify `user_id` → `{ key, label }`
- `extra_buckets` — non-staff columns after staff: Special order, Cash sale, NT sales, Quick Sale, Online Store, Shop, POS (other)

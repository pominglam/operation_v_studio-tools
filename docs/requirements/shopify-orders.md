# Shopify orders list

Operators need a page of individual Shopify sales in the ERP, not only the Staff orders daily totals.

## Scope

- Read the **`shopify_orders`** mirror (same rows as staff-orders report and demand rollups).
- Do **not** call Shopify Admin on page load.
- Admin-only (same as Reports).

## Required

1. Nav **Orders** → `/orders`.
2. One row per mirrored order: name, Toronto ordered time, channel, payment, fulfillment, subtotal before tax, line count.
3. Date range (default last 7 Toronto days), search by order name, channel, status (all / eligible / cancelled).
4. Sortable columns. Paginated.
5. Expand a row to see mirrored line SKUs / qty / ERP description.
6. Order name links to Shopify Admin when a legacy id exists.
7. Header shows filtered order count + subtotal, and last order-sync time.

## Out of scope

- Creating or editing orders.
- Live Shopify totals (tax / shipping).
- Webhook registration / faster sync (still the 12-hour reconcile until webhooks are live).

# Shopify orders

**Route:** `/orders`  
**Page:** `resources/js/pages/ShopifyOrdersPage.vue`  
**Nav:** **Orders** (admin only)

## Purpose

Paginated list of **Shopify sales** from the **`shopify_orders`** ERP mirror (same rows as the [staff orders report](staff-orders-report.md)). This is not a live Shopify pull.

Amounts are **revenue before tax** (`subtotal_shop_amount` / Shopify `currentSubtotalPriceSet.shopMoney`). Tax and shipping are not stored on the list.

## User actions

| Action                                  | Behavior                                                                                                                                          |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Open page                               | Loads last **7 calendar days** (America/Toronto). Filters persist in `page_state:shopify-orders:list:v1`. Query params `preorder`, `status`, `from`, `until` override saved state (used by `/store-preorders` order-count links). |
| **Today** / **7 days** / **This month** | Sets the date range.                                                                                                                              |
| From / Until                            | Inclusive Toronto calendar days.                                                                                                                  |
| Search                                  | Order name (`#OVS-2863` or `2863`).                                                                                                               |
| Contact                                 | Email and/or phone from the order mirror, shown in a column immediately after **Order**. Sortable. Empty when the row has not been re-pulled.     |
| Processed by                            | Configured POS staff from `pos_user_id`, or **—**. Same staff map as the [staff orders report](staff-orders-report.md).                           |
| Channel                                 | Sales-channel split from the same classifier: POS / Online Store / Shop / Quick Sale / Special order / Cash sale / NT sales / POS (other) / Unattributed. Filter still uses report buckets (staff + extras). |
| Event                                   | Tagged store event name, or **—**. Select rows and **Assign to event** (or **Clear event**).                                                      |
| Status                                  | **All**, **Eligible** (not cancelled/voided), **Cancelled / voided**.                                                                             |
| Pre-order                               | **All** or **Has pre-order item** (at least one line whose SKU/product is a store-preorder offer). Matching rows also show a **Pre-order** badge. |
| Sort column                             | Toggles asc/desc. Default newest first.                                                                                                           |
| Line count                              | Expands mirrored SKU / qty / ERP description. Store-preorder SKUs show **(PO)** on the description and a **Pre-order** badge.                     |
| Order name                              | Opens Shopify Admin in a new tab when `legacy_numeric_id` exists.                                                                                 |

Header: filtered order count, filtered subtotal, **Last sync** from `shopify_sync_state` (`orders`).

## API

| Method | Path                          | Query / notes                                                                                                                                                                                     |
| ------ | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/api/v1/shopify/orders`      | `from`, `until` (`Y-m-d`), `search`, `channel`, `status=all\|eligible\|cancelled`, `preorder=all\|only`, `sort_by` (includes `contact`), `sort_dir`, `page`, `per_page` (max 100). Response `{ data, meta, summary }`. List rows include `customer_email`, `customer_phone`, `processed_by_*`, `sales_channel_*`, and `store_event_*`. |
| GET    | `/api/v1/shopify/orders/{id}` | One order + `lines` (SKU, qty, product description, `is_store_preorder`). List rows include `has_store_preorder`, `customer_email`, and `customer_phone`. **404** if missing.                     |
| POST   | `/api/v1/shopify/orders/store-event` | Body `{ order_ids, store_event_id }` (`store_event_id` null clears). **404** if the event UUID is missing. |

`summary`: `filtered_count`, `filtered_subtotal`, `last_synced_at`, `timezone`, `revenue_currency`, `channel_options`, resolved `from_date` / `until_date`.

## Backend

| Piece                                                        | Role                                                                                    |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| `ShopifyOrderIndexController` / `ShopifyOrderShowController` | HTTP only.                                                                              |
| `ShopifyOrderQueryService`                                   | Date/channel/status filters + sort + pagination.                                        |
| `ShopifyOrderChannelPresenter`                               | Report bucket (`channel_*`) plus processed-by and sales-channel labels. Same classifier as the staff report and store events. |
| `ShopifyOrderAdminUrl`                                       | `{store_domain}/admin/orders/{legacyId}`.                                               |
| `ShopifyOrderGraphQlCustomerIdentity`                        | Maps Shopify `customer.id`, customer/order email, customer/order phone onto the mirror. |

Orders appear after **scheduled reconcile** (default **30 minutes**) or webhooks. See [maintenance.md](maintenance.md).

### Customer identity on the order mirror

`shopify_orders` stores Shopify’s own customer link when present:

| Column           | Source                                                         |
| ---------------- | -------------------------------------------------------------- |
| `customer_gid`   | `order.customer.id`                                            |
| `customer_email` | Customer default email, else order email (trimmed, lowercased) |
| `customer_phone` | Customer default phone, else order phone (trimmed)             |

The Orders list shows **email** and **phone** in the **Contact** column (admin-only). Incremental reconcile, webhooks, and **historical order backfill** all write them. Existing rows stay empty until a re-pull. `shopify_customers.phone` is filled by the customers sync.

Identified people (GID / email / phone merge, no PII on the grid) are on **[Customer retention](customer-retention.md)**.

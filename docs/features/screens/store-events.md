# Store events

**Route:** `/store-events`  
**Page:** `resources/js/pages/StoreEventsPage.vue`  
**Nav:** **Events** → **Store events** (admin only). Header links to [Marketing notes](store-marketing-notes.md) for the sheet Other column.

## Purpose

Catalog of in-store community events and the day’s Shopify orders so operators can tag event sales. Not Bandai TCG+.

## User actions

| Action | Behavior |
| --- | --- |
| Open page | Lists events collapsed (newest start first). Expand an event to see **one row per day**, then that day’s eligible orders. |
| Search name | Filters events by name (Enter or Search). |
| Add / Edit | Name, starts, ends, notes, cancelled. End date must be on/after start. |
| Delete | Confirm, then remove the event. Tagged orders keep their Shopify data; `store_event_id` is cleared (`nullOnDelete`). |
| Expand event / day | Collapse or reopen the summary → day → orders tree. |
| Check an order | Tags it to this event immediately (normal color, counts update locally). Uncheck removes the tag if it belonged here. Gray = not this event; another event name shows in parentheses when tagged elsewhere. |
| Line preview | Click the compact SKU/qty/description line to expand the full line table. |

**Orders** on the summary = tagged count. Day row shows `N in · M total`. **Event $ (before tax)** is tagged Shopify `subtotal_shop_amount` (same as Orders / staff report). Individual order amounts are also before tax.

**Processed by** = configured POS staff from `pos_user_id`. **Channel** = POS / Online Store / Shop / Quick Sale / Special order / Cash sale / NT sales / POS (other) / Unattributed — same classifier as [Staff orders](staff-orders-report.md) and [Orders](shopify-orders.md).

## API

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/store-events` | Optional `search`. `{ data: StoreEventCalendar[] }` with nested `days[].orders`. |
| POST | `/api/v1/store-events` | 201. Body: `name`, `starts_on`, `ends_on`, `notes`, `cancelled`. |
| PATCH | `/api/v1/store-events/{uuid}` | Same body. 404 if missing. |
| POST | `/api/v1/store-events/{uuid}/orders` | `{ order_ids, included }`. Tags or untags. 404 if event missing. |
| DELETE | `/api/v1/store-events/{uuid}` | 204. 404 if missing. |

`id` in JSON is the row UUID. Order rows reuse Shopify order fields plus `in_event`, `processed_by_*`, `sales_channel_*`.

Batch assign also lives on **Orders**: `POST /api/v1/shopify/orders/store-event`.

## Backend

| Piece | Role |
| --- | --- |
| `StoreEventService` | Create / update / delete |
| `StoreEventCalendarAssembler` | Day rows + eligible orders in the event date range |
| `StoreEventOrderAssignmentService` | Tag / untag `shopify_orders.store_event_id` |
| `store_events` | `name`, `starts_on`, `ends_on`, `notes`, `cancelled_at` |
| `shopify_orders.store_event_id` | Nullable FK, null on event delete |

Eligible = not cancelled and not voided (`ShopifyOrderDemandEligibility`). Day boundaries use `shopify.staff_order_report.timezone`.

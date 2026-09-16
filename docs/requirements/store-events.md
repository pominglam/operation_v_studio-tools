# Store events

In-store community events (build nights, workshops, competitions). Separate from Bandai **TCG+** events.

## Now

Operators record each event: **name**, **start date**, **end date** (same day if one-session), optional **notes**, and **cancelled**.

The list is a tree:

- **Event summary** — name, date range, tagged order count, tagged spend (before tax).
- **One row per calendar day** in the range (America/Toronto, same clock as the [staff orders report](../features/screens/staff-orders-report.md)).
- **Orders that day** — every eligible Shopify order (not cancelled/voided). Checked / normal color = tagged to this event. Gray = not this event. Click the line preview to expand SKU / qty / description.

**Event $** = sum of tagged eligible `subtotal_shop_amount` for that event (or day). Spreadsheet notes can stay as a historical hint until tagging is complete.

Tag from this screen (checkbox) or batch-assign from **Orders**.

**Processed by** and **Channel** use the same attribution classifier as the staff orders report and the Orders list (`ShopifyOrderStaffBucketClassifier` / `ShopifyOrderChannelPresenter`). Adding staff or extra buckets (including Special order, Cash sale, and NT sales) updates all three.

Dates are calendar days in `config('shopify.staff_order_report.timezone')` (America/Toronto).

Sheet **Other** (restock posts, videos, ads) lives on [Marketing notes](store-marketing-notes.md). Those dates are not Event $.

## Next (not built)

- Retention / acquisition report: first-order channel includes these events.
- Overlay marketing-note dates on sales charts (markers only).

# Special orders — Asia

Admin-only workflow for Asia-sourced custom customer orders. Employee role cannot access these routes (same as Products, POs, etc.).

## Routes

| Web route | Page |
| --- | --- |
| `/special-orders` | `SpecialOrdersPage.vue` — searchable/sortable list |
| `/special-orders/new` | `SpecialOrderDetailPage.vue` — create request |
| `/special-orders/:id` | `SpecialOrderDetailPage.vue` — edit request + merchandiser quote |

Nav: **Special Orders** (admin nav only).

## User actions

Detail fields **auto-save on blur** (or immediately on dropdown change), matching PO line edits and product inline edits. Quote and Reconciliation **Weight × rate** stay patched in place after save — the panel is not remounted from the response (that snapped **Weight × rate** back to **Amount** before kg could be entered). Only **Create order** (new route) and **Delete order** use explicit buttons; delete keeps a confirmation dialog.

**Create order** applies maintenance **default shipping** (100 RMB by default) and immediately queues a **full (9-site)** competitor price crawl when the product name is at least 3 characters.

### Customer request (you)

- **Contact media** — Instagram or Facebook
- **Customer contact** — handle or profile reference
- **Product name** — editable in the page title (`Special order — …`); auto-saves on blur. **Autocomplete** queries Gundam Hangar (JSON API), then Hobby Sense and Argama (Shopify suggest) after 2+ characters — pick a row to apply the retailer’s canonical title and save, or **Close** / Escape / click outside to dismiss without changing the typed name (list stays closed until you type again). **Competitor prices** — auto-starts an **async parallel** crawl of all 9 CAD retailers when the name is saved; the **Canadian competitor prices** panel (top of the workspace) lists each site with “Searching…” until results land; polls `GET …/{uuid}` while `competitor_prices_refresh_status` is `queued`/`running`. Optional **Refresh fast (4)** for the quick subset.
- **Customer visual** — image upload (after order is created); remove and re-upload if wrong
- **Notes** — optional free text

### Merchandiser quote (product research)

Panel title: **Merchandiser**. **Quote** subsection only — **vendor** (datalist from ERP `products.vendor` distinct list via `GET /api/v1/products/filter-options`), product/shipping cost and shipping delay for pricing the customer offer.

**Quote**

- **Product cost** + currency (CAD, RMB/CNY, HKD, JPY)
- **Shipping cost** — **Amount** (manual foreign amount + currency) or **Weight × rate** (kg × maintenance **RMB/kg**, default **29**); switching to Weight stays on that mode after save. Typing kg must not crash or snap back to Amount (number inputs can emit a numeric value). Amount is kept until kg is entered; then shipping RMB = kg × the RMB/kg rate and landed CAD uses that shipping amount.
- **Shipping delay** — how long until the product can be received (amount + days/weeks/months); defaults to **6 weeks** on new orders
- **Landed cost (CAD)** — auto-computed when merchandiser fields save (FX via Google Finance quote pages, cached per day; Frankfurter fallback if Google is unavailable). The FX note shows **foreign units per 1 CAD** (e.g. `4.891 RMB per 1 CAD`), matching PO-style quoting.

- **Product visual** — image upload; remove and re-upload if wrong

### Customer offer (after merchandiser quote)

Available once the merchandiser quote is complete (`quote_status: quoted`).

**Pricing summary** (compact box when quoted): **Original | CAD** grid sized to content. **Fulfillment** sits in a middle column; **Customer message** (disclaimer DM) in a third column on the right. Merchandiser/our **multipliers** sync bidirectionally with **commission/markup CAD** on blur (same pattern as deposit % ↔ CAD). Middle column shows **pay merchandiser** (landed + commission) and **component selling total**. Lock in offer below the grid.

**Additive pricing model** (all amounts in CAD unless noted):

| Step | Rule |
| --- | --- |
| Source cost | Operator input (foreign amount + FX → CAD) |
| Shipping cost | Operator input (foreign amount + FX → CAD), or **weight (kg) × maintenance RMB/kg** → RMB amount + FX → CAD |
| **Landed cost** | Source CAD + shipping CAD |
| **Merchandiser commission** | `landed × (merchandiser multiplier − 1)` by default, or **CAD override**; merchandiser price = landed + commission |
| **OPV markup** | `spread × (our multiplier − 1)` on **spread = landed + merchandiser commission**, or **CAD override** |
| **Selling price** | `spread × our multiplier` (= spread + OPV markup) |

**Maintenance caps** (defaults **$50** merchandiser commission, **$150** OPV margin — configurable under **Maintenance → Special order — pricing caps**): formula-derived commission and margin are capped; explicit CAD overrides on the order bypass caps.

Multiplier ↔ CAD override sync on blur (same pattern as deposit). **Selling price** blur derives OPV markup (`selling − spread`) and syncs our multiplier. Summary middle column shows **pay merchandiser** (landed + commission) and **component total** (landed + commission + OPV margin).

| Control | Default | Effect |
| --- | --- | --- |
| **Merchandiser multiplier** | **1.1×** on landed | Blur syncs commission CAD; commission CAD blur syncs multiplier |
| **Our multiplier** | **1.4×** on spread | Blur syncs OPV markup CAD; markup CAD blur syncs our multiplier |
| **Commission overrides** | optional CAD | Merchandiser override updates selling price; OPV override does not change merchandiser commission |

- **Deposit** — **percent** (default **20%**) or **Deposit (CAD)** override; balance uses whichever deposit source is active
- **Lock in offer** — locks **customer price**, multipliers, OPV/deposit overrides, and deposit only (`offer_locked_at`); merchandiser tier stays editable (merchandiser commission can still change without changing locked customer price)
- **Unlock offer** — clears `offer_locked_at` so customer price, OPV markup, and deposit can be edited again (confirm dialog). Blocked after deposit received, merchandiser order placed, or product received.

### Reconciliation (after offer is locked)

Own **panel** below **Customer offer** (same bordered section style as Merchandiser / Customer request). Hidden until **Lock in offer**.

**Inputs** (auto-save on blur):

- **Product cost** + currency (actual paid) — **pre-filled from merchandiser quote** when the offer is locked (or on first open if still empty); edit as needed
- **Shipping cost** + currency (actual paid) — same pre-fill from quote; supports **Amount** or **Weight × rate** like the merchandiser quote. Switching to **Weight × rate** stays on that mode after save. Typing kg must not crash the page (number inputs can emit a numeric value; preview/save coerce it). Amount is kept until kg is entered; then it becomes kg × the RMB/kg rate.
- **Received date** (`actual_arrival_at`) — when the product was received (replaces actual shipping delay in UI)

**Settlement grid** (Original \| CAD, same shape as pricing summary):

- Source / shipping → **Actual landed (CAD)**
- Merchandiser commission (quote multiplier × actual landed)
- **Pay merchandiser** (actual landed + commission)
- **Customer price** (read-only — locked offer)
- **OPV margin** (customer price − pay merchandiser)
- **Customer message** — auto-generated DM from the **Maintenance** template (`{product_name}`, `{price}`, `{deposit_percent}`) with **Copy message** once price and deposit are set
- **Pricing status** — `priced` when customer price and deposit (percent or CAD override) are set

- Customer-offer fields auto-save on blur until locked; merchandiser tier keeps auto-saving after lock.

### Fulfillment (after offer is locked)

Shown beside the pricing summary when quoted (legacy layout uses a separate panel below). **Order proof** upload lives under **Merchandiser ordered**.

Available once **Lock in offer** has been clicked (`offer_locked_at` set).

| Action | When | Effect |
| --- | --- | --- |
| **Cash payment** | Offer locked + priced | Enter **Cash received (CAD)** — cumulative total cash collected in store. Partial: field stays editable until paid in full; **paid in full** locks like other milestones (`balance_received_at`). Auto-sets deposit/balance milestones when thresholds are met. |
| **Shopify deposit invoice** | Offer locked + priced + deposit due, deposit not yet received | Fulfillment: **Create deposit invoice in Shopify** — hidden once deposit is received (cash or milestone) or customer paid in full. **Remove invoice record** (confirm) clears the ERP link after a Shopify draft is deleted so a new invoice can be created. Does not call Shopify. |
| **Shopify balance invoice** | Deposit invoice exists + **In hand at OPV**, balance not paid | Fulfillment: **Create balance invoice in Shopify** — hidden when paid in full. **Remove invoice record** same as deposit; deposit record cannot be removed while a balance invoice record exists. |
| **Merchandiser ordered** | After offer locked + quote complete | Sets `merchandiser_ordered_at` and auto-calculates **ETA** (deposit may be marked before or after) |
| **Mark in hand** | After merchandiser ordered | Sets `product_received_at` — OPV has the kit (one-way milestone; list badge **In hand**). Distinct from **Received date** in Reconciliation |
| **Order placement proof** | After offer locked | Image upload (screenshot/photo from merchandiser); replace or remove |

- **ETA** — `merchandiser_ordered_at` + shipping delay (`receive_delay_days`); stored as `estimated_arrival_at` (date). Recalculates if shipping delay changes after the merchandiser order is placed.
- Buttons are one-way (timestamp recorded once); completed steps show the recorded time instead of the button.

### Customer thinking / reject / revive

- **Customer is thinking** — when the customer wants time to decide (`customer_considering_at` timestamp). Hidden from the default **Active** list; use **Lifecycle → Customer thinking** to review. **Resume active quote** clears the flag.
- **Reject order** — soft-close when the customer declines (`rejected_at` timestamp). Pricing, notes, and uploads are kept.
- **Revive order** — clears `rejected_at` if the customer returns later.
- Default list filter (**Lifecycle → Active**) excludes rejected and customer-thinking orders. Use **Customer thinking**, **Rejected**, or **All** to find them.
- **Lock in offer** and fulfillment actions are disabled while rejected.

### List page

- Search contact / product name / notes
- Filter: contact media
- **Workflow timeline** — **All (N)**, **Quoting (N)**, and **Processing (N)** quick-group pills, then individual step pills with per-status counts. **Quoting** = pending quote through offer locked; **Processing** = deposit through **Done**. **Done** = paid in full **and** in hand at OPV. Click a pill to show/hide that slice; default visible: active pipeline (excludes **Customer thinking** and **Rejected**). Session-persisted (`special-orders:workflow-filter:v2`).
- Sort: contact, media, product name, **customer price**, **balance** (remaining owed), **ETA**, created, updated
- **Status** column — badge-styled dropdown (`SpecialOrderListStatusSelect.vue`) when milestone actions exist: e.g. **Priced** → **Customer thinking**, **Resume active quote**, forward milestones (deposit, ordered, in hand, paid in full, **Done**), **Rejected** (confirm), **Revive**. Read-only badge when no actions apply (**Done**, paid in full awaiting handoff, etc.).
- Reject order (confirm) or revive from detail; delete order (confirm) — removes DB row + stored images

## API

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/special-orders/filter-options` | Media, currency, quote/pricing/lifecycle options |
| GET | `/api/v1/special-orders/product-name-suggestions` | Fast name autocomplete (`q`, optional `limit`) — Gundam Hangar + Hobby Sense + Argama fetched **in parallel** via `ExternalHtmlClient::poolGetForSuggest` with **per-site** rate limits (`price_research:site:{site_key}:suggest`; over-limit sources skipped, others still return) |
| GET | `/api/v1/special-orders` | Paginated index (`search`, `contact_media[]`, `quote_status`, `pricing_status`, `lifecycle_status`, `sort_by`, `sort_dir`; default `lifecycle_status=active`) |
| POST | `/api/v1/special-orders` | Create request; sets default shipping from maintenance; queues competitor crawl (`full`) when product name ≥ 3 chars |
| GET | `/api/v1/special-orders/{uuid}` | Show |
| PATCH | `/api/v1/special-orders/{uuid}` | Update request and/or merchandiser fields (`vendor` nullable string among quote fields) |
| POST | `/api/v1/special-orders/{uuid}/lock-offer` | Lock customer offer (price + deposit) |
| POST | `/api/v1/special-orders/{uuid}/unlock-offer` | Clear offer lock (before deposit, merchandiser order, or product receipt) |
| POST | `/api/v1/special-orders/{uuid}/competitor-prices/refresh` | Queue async parallel competitor crawl for `product_name` (`scope`: `fast` or `full`; auto-search uses `full`). **202** + order with `competitor_prices_refresh_status`, pending per-site rows; poll GET until `completed`/`failed`. |
| POST | `/api/v1/special-orders/{uuid}/revive` | Clear rejection |
| POST | `/api/v1/special-orders/{uuid}/deposit-received` | Mark deposit paid (timestamp) |
| POST | `/api/v1/special-orders/{uuid}/balance-received` | Mark balance paid (timestamp) |
| POST | `/api/v1/special-orders/{uuid}/cash-received` | Record cash received `{ cash_received_cad }` |
| POST | `/api/v1/special-orders/{uuid}/mark-customer-considering` | Customer is thinking about the quote |
| POST | `/api/v1/special-orders/{uuid}/clear-customer-considering` | Resume active quote |
| POST | `/api/v1/special-orders/{uuid}/shopify-deposit-invoice` | Create + send Shopify deposit draft invoice |
| DELETE | `/api/v1/special-orders/{uuid}/shopify-deposit-invoice` | Clear ERP deposit invoice link (no Shopify delete) |
| POST | `/api/v1/special-orders/{uuid}/shopify-balance-invoice` | Create + send Shopify balance draft invoice |
| DELETE | `/api/v1/special-orders/{uuid}/shopify-balance-invoice` | Clear ERP balance invoice link (no Shopify delete) |
| GET | `/api/v1/shopify/customers/suggest?q=` | Customer search (24h mirror; syncs if stale) |
| POST | `/api/v1/shopify/customers` | Create Shopify customer (`email`, `first_name`, `last_name`) |
| POST | `/api/v1/special-orders/{uuid}/merchandiser-ordered` | Mark merchandiser ordered + compute ETA |
| POST | `/api/v1/special-orders/{uuid}/product-received` | Mark kit in hand at OPV (`product_received_at`; requires merchandiser ordered) |
| DELETE | `/api/v1/special-orders/{uuid}` | Delete |
| POST | `/api/v1/special-orders/{uuid}/customer-visual` | Multipart `file` |
| POST | `/api/v1/special-orders/{uuid}/product-visual` | Multipart `file` |
| POST | `/api/v1/special-orders/{uuid}/merchandiser-order-proof` | Multipart `file` — order placement proof |
| DELETE | `/api/v1/special-orders/{uuid}/visuals/{customer\|product\|merchandiser-order-proof}` | Remove uploaded image |
| GET | `/api/v1/special-orders/{uuid}/visuals/{customer\|product\|merchandiser-order-proof}` | Inline image |

## Storage

Images: `storage/app/special_orders/{uuid}/` on the `local` disk.

## Future

Merchandiser-specific role can be added later (middleware allow-list + nav gating); today everything is admin-only via external-access cookie.

# Custom orders — Asia

Admin-only workflow for Asia-sourced custom customer orders. Employee role cannot access these routes (same as Products, POs, etc.).

## Routes

| Web route | Page |
| --- | --- |
| `/custom-orders/asia` | `CustomAsiaOrdersPage.vue` — searchable/sortable list |
| `/custom-orders/asia/new` | `CustomAsiaOrderDetailPage.vue` — create request |
| `/custom-orders/asia/:id` | `CustomAsiaOrderDetailPage.vue` — edit request + merchandiser quote |

Nav: **Custom Orders** (admin nav only).

## User actions

Detail fields **auto-save on blur** (or immediately on dropdown change), matching PO line edits and product inline edits. Only **Create order** (new route) and **Delete order** use explicit buttons; delete keeps a confirmation dialog.

### Customer request (you)

- **Contact media** — Instagram or Facebook
- **Customer contact** — handle or profile reference
- **Product name** — editable in the page title (`Custom order — …`); auto-saves on blur. **Autocomplete** queries Gundam Hangar (JSON API), then Hobby Sense and Argama (Shopify suggest) after 2+ characters — pick a row to apply the retailer’s canonical title and save. **Competitor prices** — auto-starts an **async parallel** crawl of all 8 CAD retailers when the name is saved; the **Canadian competitor prices** panel (top of the workspace) lists each site with “Searching…” until results land; polls `GET …/{uuid}` while `competitor_prices_refresh_status` is `queued`/`running`. Optional **Refresh fast (4)** for the quick subset.
- **Customer visual** — image upload (after order is created); remove and re-upload if wrong
- **Notes** — optional free text

### Merchandiser quote (product research)

Panel title: **Merchandiser**. **Quote** subsection only — product/shipping cost and shipping delay for pricing the customer offer.

**Quote**

- **Product cost** + currency (CAD, RMB/CNY, HKD, JPY)
- **Shipping cost** + currency
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
| Shipping cost | Operator input (foreign amount + FX → CAD) |
| **Landed cost** | Source CAD + shipping CAD |
| **Merchandiser commission** | `landed × (merchandiser multiplier − 1)` by default, or **CAD override**; merchandiser price = landed + commission |
| **OPV markup** | `spread × (our multiplier − merchandiser multiplier)` on **spread = landed + merchandiser commission**, or **CAD override** |
| **Selling price** | `landed + merchandiser commission + OPV markup` |

**Maintenance caps** (defaults **$50** merchandiser commission, **$150** OPV margin — configurable under **Maintenance → Custom Asia order — pricing caps**): formula-derived commission and margin are capped; explicit CAD overrides on the order bypass caps.

Multiplier ↔ CAD override sync on blur (same pattern as deposit). **Selling price** blur derives OPV markup (`selling − spread`) and syncs our multiplier. Summary middle column shows **pay merchandiser** (landed + commission) and **component total** (landed + commission + OPV margin).

| Control | Default | Effect |
| --- | --- | --- |
| **Merchandiser multiplier** | **1.1×** on landed | Blur syncs commission CAD; commission CAD blur syncs multiplier |
| **Our multiplier** | **1.4×** spread over merchandiser | Blur syncs OPV markup CAD; markup CAD blur syncs our multiplier |
| **Commission overrides** | optional CAD | Merchandiser override updates selling price; OPV override does not change merchandiser commission |

- **Deposit** — **percent** (default **20%**) or **Deposit (CAD)** override; balance uses whichever deposit source is active
- **Lock in offer** — locks **customer price**, multipliers, OPV/deposit overrides, and deposit only (`offer_locked_at`); merchandiser tier stays editable (merchandiser commission can still change without changing locked customer price)
- **Unlock offer** — clears `offer_locked_at` so customer price, OPV markup, and deposit can be edited again (confirm dialog). Blocked after deposit received, merchandiser order placed, or product received.

### Reconciliation (after offer is locked)

Own **panel** below **Customer offer** (same bordered section style as Merchandiser / Customer request). Hidden until **Lock in offer**.

**Inputs** (auto-save on blur):

- **Product cost** + currency (actual paid)
- **Shipping cost** + currency (actual paid)
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
| **Mark deposit in** | Customer price + deposit set | Sets `deposit_received_at` (Toronto time, ISO in API) |
| **Merchandiser ordered** | After deposit received | Sets `merchandiser_ordered_at` and auto-calculates **ETA** |
| **Product received** | After merchandiser ordered | Sets `product_received_at` — product is in hand (one-way milestone; distinct from **Arrival date** in merchandiser Actual) |
| **Order placement proof** | After offer locked | Image upload (screenshot/photo from merchandiser); replace or remove |

- **ETA** — `merchandiser_ordered_at` + shipping delay (`receive_delay_days`); stored as `estimated_arrival_at` (date). Recalculates if shipping delay changes after the merchandiser order is placed.
- Buttons are one-way (timestamp recorded once); completed steps show the recorded time instead of the button.

### Reject / revive

- **Reject order** — soft-close when the customer declines (`rejected_at` timestamp). Pricing, notes, and uploads are kept.
- **Revive order** — clears `rejected_at` if the customer returns later.
- Rejected orders are hidden from the default list filter (**Lifecycle → Active**). Use **Rejected** or **All** to find them.
- **Lock in offer** and fulfillment actions are disabled while rejected.

### List page

- Search contact / product name / notes
- Filter: contact media, quote status (pending vs quoted), **pricing status** (pending vs priced), **lifecycle** (active / rejected / all; default active)
- Sort: contact, media, product name, receive in, **customer price**, **deposit**, **ETA**, created, updated
- **Status** column — one badge for the **latest** step: Pending quote → Quoted → Priced → Offer locked → Deposit in → Ordered → **Received**, or **Rejected**
- Reject order (confirm) or revive from detail; delete order (confirm) — removes DB row + stored images

## API

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/custom-asia-orders/filter-options` | Media, currency, quote/pricing/lifecycle options |
| GET | `/api/v1/custom-asia-orders/product-name-suggestions` | Fast name autocomplete (`q`, optional `limit`) — Gundam Hangar + Hobby Sense + Argama fetched **in parallel** via `ExternalHtmlClient::poolGetForSuggest` with **per-site** rate limits (`price_research:site:{site_key}:suggest`; over-limit sources skipped, others still return) |
| GET | `/api/v1/custom-asia-orders` | Paginated index (`search`, `contact_media[]`, `quote_status`, `pricing_status`, `lifecycle_status`, `sort_by`, `sort_dir`; default `lifecycle_status=active`) |
| POST | `/api/v1/custom-asia-orders` | Create request |
| GET | `/api/v1/custom-asia-orders/{uuid}` | Show |
| PATCH | `/api/v1/custom-asia-orders/{uuid}` | Update request and/or merchandiser fields |
| POST | `/api/v1/custom-asia-orders/{uuid}/lock-offer` | Lock customer offer (price + deposit) |
| POST | `/api/v1/custom-asia-orders/{uuid}/unlock-offer` | Clear offer lock (before deposit, merchandiser order, or product receipt) |
| POST | `/api/v1/custom-asia-orders/{uuid}/competitor-prices/refresh` | Queue async parallel competitor crawl for `product_name` (`scope`: `fast` or `full`; auto-search uses `full`). **202** + order with `competitor_prices_refresh_status`, pending per-site rows; poll GET until `completed`/`failed`. |
| POST | `/api/v1/custom-asia-orders/{uuid}/revive` | Clear rejection |
| POST | `/api/v1/custom-asia-orders/{uuid}/deposit-received` | Mark deposit received (timestamp) |
| POST | `/api/v1/custom-asia-orders/{uuid}/merchandiser-ordered` | Mark merchandiser ordered + compute ETA |
| POST | `/api/v1/custom-asia-orders/{uuid}/product-received` | Mark product received in hand (`product_received_at`; requires merchandiser ordered) |
| DELETE | `/api/v1/custom-asia-orders/{uuid}` | Delete |
| POST | `/api/v1/custom-asia-orders/{uuid}/customer-visual` | Multipart `file` |
| POST | `/api/v1/custom-asia-orders/{uuid}/product-visual` | Multipart `file` |
| POST | `/api/v1/custom-asia-orders/{uuid}/merchandiser-order-proof` | Multipart `file` — order placement proof |
| DELETE | `/api/v1/custom-asia-orders/{uuid}/visuals/{customer\|product\|merchandiser-order-proof}` | Remove uploaded image |
| GET | `/api/v1/custom-asia-orders/{uuid}/visuals/{customer\|product\|merchandiser-order-proof}` | Inline image |

## Storage

Images: `storage/app/custom_asia_orders/{uuid}/` on the `local` disk.

## Future

Merchandiser-specific role can be added later (middleware allow-list + nav gating); today everything is admin-only via external-access cookie.

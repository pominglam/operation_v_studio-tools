# Store preorders

**Page:** `resources/js/pages/StorePreordersPage.vue`  
**Route:** `/store-preorders` (admin nav **Catalog → Store preorders**; hidden for employee role)  
**Pick list:** `/preorders` ([preorders.md](preorders.md))  
**Spec:** `docs/requirements/store-preorders.md`

## Purpose

Operator list of **customer store pre-order offers**. An offer is a status on a normal ERP product (created when the offer is opened), not a separate product type.

Open store-preorder products are **hidden from the Products grid by default** (`store_preorder=exclude`). Pushing them to Shopify asks for confirm: Shopify price is the **deposit**; a set cap becomes tracked inventory; **no cap** is untracked + continue selling. The only customer shelf is **`/collections/pre-orders`** (main nav **Preorders**, before Miscellaneous). Cap edits on an already-pushed product update Shopify automatically.

## User actions

| Action   | UI                                                                                                                              | API                                       |
| -------- | ------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| Browse   | Paginated grid: image, name/SKU, Open/Closed, **Units** (eligible unit qty, order count under), cap (editable while open), deposit, sell $, closing, Plamod ETA. Header shows all-time eligible units with order count under (link to `/orders?preorder=only`). | `GET /api/v1/store-preorders`             |
| Edit cap | Inline number on an open row; empty = no cap; saves on blur/Enter                                                               | `PATCH /api/v1/store-preorders/{id}/cap`  |
| Bulk edit | Row checkboxes + **Edit selected**; apply cap, deposit %, sell $ (snapped to X.99), and/or closing to open offers. Closed rows are skipped. | `POST /api/v1/store-preorders/bulk-update` |
| Search   | SKU or product name                                                                                                             | `GET /api/v1/store-preorders?search=`     |
| Status   | Open (default) / Closed / All                                                                                                   | `GET /api/v1/store-preorders?status=`     |
| Closing  | All dates, or one `window_ends_on` from the dates present for the current status                                                | `GET /api/v1/store-preorders?closes_on=`  |
| Units    | **Units > 0** checkbox: only kits with eligible (not cancelled/voided) Shopify preorder units                                   | `GET /api/v1/store-preorders?has_units=1` |
| Sort     | Closing soon (default), opened, name                                                                                            | `GET /api/v1/store-preorders?sort_by=`    |
| Close    | Confirm dialog; remaining becomes 0; ERP product stays. Open offers whose **Closing** day is before today (America/Toronto) are closed automatically at **00:15** via the same close path, after a Shopify qty-0 push. Blank closing dates stay open. | `POST /api/v1/store-preorders/{id}/close` · `php artisan store-preorders:close-expired` |
| Delete   | Confirm dialog; removes the offer (can reopen from pick list). Deletes the ERP product only when it has no PO/inventory history. Shopify: **untag** `sp:store-preorder` when the ERP product stays; **delete** the Shopify product when ERP is gone and the SKU has no Shopify order | `DELETE /api/v1/store-preorders/{id}`     |
| Bulk delete | Row checkboxes + **Delete selected** confirm; same delete rules as a single offer                                               | `POST /api/v1/store-preorders/bulk-delete` |
| Push to Shopify | **Push to store** confirm (all open, or selected). Deposit price, `sp:store-preorder` tag, Pre-order collection only. Opening an offer queues Shopify publish (listing first, photos when the Plamod crawl finishes). | `POST /api/v1/store-preorders/push-shopify` |
| Add offer | **Add offer** dialog. Optional product URL → crawl when a host crawler exists (Fuwa Fuwa / `fuwafuwaland.ca` first). Prefills name, description, photos, `OVS-{slug}` SKU, ETA; retailer $ is a note only. No URL: type name / description, upload photos, drag-reorder. Sell $, deposit (Maintenance default 20%), cap, closing, ETA are operator-entered. Save unique-checks SKU, creates the ERP product, attaches photos, opens the offer, and queues Shopify. | `POST /api/v1/store-preorders/listing-preview` · `POST /api/v1/store-preorders/listing-photos` · `GET /api/v1/store-preorders/listing-photos/{id}` · `POST /api/v1/store-preorders/manual` |

## Add offer (other sources)

On `/store-preorders`, **Add offer** opens a create dialog. A product URL is optional.

- Paste a supported PDP URL (Enter, paste, or **Crawl**). Hosts without a crawler return **No crawler for {host}**. The first crawler is Shopify JSON (`/products/{handle}.js`) for **fuwafuwaland.ca**.
- Crawl fills name, description HTML, photos (downloaded to a short-lived staging folder), suggested SKU `OVS-{slug}` from the title, and ETA when the page text has a month/year. Retail price is shown as a note and is **not** copied into Sell $.
- No URL: type the name (SKU prefills `OVS-{slug}` until the SKU field is edited), description, photos (upload + drag-reorder).
- Confirm requires SKU, name, and Sell $. Unique SKU is checked on save only (not reserved). Deposit defaults from Maintenance OPV catalog settings. Cap / closing / ETA are optional. Click-outside closes only when press and release are both on the dimmed backdrop — typing Sell $ (or a click that starts in the form) does not dismiss the dialog.
- Save creates the ERP product (`department` model kits, qty 0), writes the description as preferred source `other`, attaches staged photos as `manual_upload`, stores `eta_date` on the offer, and queues Shopify (with images when photos exist). No Plamod recrawl.

## Open from Plamod pick list

On `/preorders`, the pick list defaults to **confirmed live Plamod preorders only**: a Plamod **preorder price** is present, closing date is still open (or blank), and the SKU is **not** on the current Plamod in-stock snapshot. Stock-only catalog leftovers, in-stock kits, and closed offers stay hidden unless leftovers is on; those rows cannot be opened.

Check one or more live kits (already-open rows are disabled), then **Open & push to Shopify**:

- Confirm lists each selected kit with its own **Sell $**, **Deposit %**, **Cap**, and **Closing date**.
- Closing is pre-filled as **one day before** Plamod `po_due_date` (24h to place the Plamod order) and can be overridden per kit.
- Deposit % defaults to the Maintenance value (**20**) per kit.
- Under each Sell $ field, **Landed** is Plamod PO cost × (1 + Plamod restock shipping estimate, default **5%**) and **×** is Sell $ ÷ Landed (updates as the price is typed).
- New SKUs create an ERP `products` row (`vendor` Plamod, `available_qty` 0). Suggested sell $ is **estimated landed** (PO cost + restock shipping %, default 5%) × OPV catalog margin (default 1.5×), closest X.99 (ties go up). A typed Sell $ override is snapped to the closest X.99.
- Already-open SKUs are skipped. Closed SKUs are reopened on the same offer row.
- After confirm, the pick list links to **View store preorders**. Opening attaches the Plamod pick-list photo onto the ERP product when that file is already on disk, scrapes the Plamod PDP **full description** into `product_external_contents` (`source=plamod`, preferred unless Manual/`other`), then queues a Shopify push **with images**. Kits that still have no photo get a listing-only push plus a Plamod PDP zip crawl. Store-preorder products keep **Plamod photos only** (pick-list `plamod_preorder` and/or PDP zip `plamod`). HLJ and other catalog scrapers do not attach images. **Add offer** photos stay `manual_upload`.
- Open **and closed** offers that still have no **real** Shopify-enabled images are checked daily at **07:00 America/Toronto** (`store-preorders:refresh-missing-photos`). Plamod hub/PDP **“No image”** graphics are treated as missing (not uploaded). Attach the pick-list photo when it is a real kit shot; otherwise recrawl the Plamod PDP. A leftover placeholder is stripped and Shopify media is cleared so the fake graphic does not stay on the store.
- **Push to store** remains a retry for already-open offers.

## Customer storefront

- Collection: `https://operationvstudio.com/collections/pre-orders` (smart collection on tag `sp:store-preorder`). Closed offers stay tagged. Shopify **info** / create pushes write tags; **images-only** updates omit `tags` so a photo job cannot drop `sp:store-preorder`. `shopify:store-preorders-shelf-sync` retags ERP offers that lost the tag.
- Main nav **Preorders** (before Miscellaneous) → `/collections/pre-orders` on live Rise.
- Default sort is **Close date** (soonest first). Shopify collection order is maintained by `collectionReorderProducts` (open kits first, soonest close date; closed kits after). Theme page size is the collection setting (**16**). Unfiltered Opened browse uses native Shopify pages. Filters use a slim product index (no full-card dump) and page **16** cards; missing cards are fetched from the collection page they live on. A product handle is shown once on every product listing (collection grids, featured collections, search). Filter remounts keep the live card and drop clones. Collection filters (live): **Close Date** (Opened / Closed parents check or clear their **Due {date}** children, same as Series; default Opened + all open Due dates), **Brand** (Gunpla, 30 Minutes, Pokémon, MODEROID, Other), **Grade** (prefix + full name, e.g. `HG - High Grade`, `MGEX - Master Grade Extreme`), **Series** grouped as Gundam (UC + AU) / 30 Minutes / Pokémon / Others (Others collapsed on first load). Expanded parents stay open after the last child is unchecked. Parent Series checkboxes select visible children. Empty date/Brand/Grade/Series options hide; Opened and Closed stay visible. Mobile drawer groups stay inline accordions (not Dawn submenu pages) and keep their open/closed state when the drawer is closed. URL params: `ovs_po_closes`, `ovs_po_status`, `ovs_po_brand`, `ovs_po_grade`, `ovs_po_series`, `ovs_po_page` (filtered pager only).
- PDP and collection cards show the full catalog price like a normal product, then **Deposit $X by {close date}** and **ETA {Plamod eta date}** when known. Homepage **Featured products** cards show the catalog price only (no deposit / close / ETA). Cap is not shown.
- PDP shows a **Pre-order** label under the title. The button is **Pre-order** while the window is open (same as add-to-cart; checkout still charges the deposit). After the close date — or when the kit is no longer available — the button is **Preorder closed** on collection listings, search, related products, and the PDP. **Homepage** Featured Products / Latest Arrivals stay browse-only (no listing CTA). Add-to-cart is disabled on closed offers. Shop Pay and pickup (“ready in 1 hour”) are hidden on these PDPs.
- Shopify product title is `{name} (PO)` so the Admin order line snapshots that suffix. The storefront strips `(PO)` from the visible title. Add-to-cart also sets line properties `(PO)` = `Pre-order` and `Pre-order` = `Deposit $X`, plus a cart attribute `preorder=true`. After order sync, ERP tags the Shopify order `preorder`. `/orders` badges the order and line, and the **Pre-order → Has pre-order item** filter lists only those orders.
- Remaining balance is due when the kit arrives (operator preorder-orders list still later).

## Later (not this slice)

Paid deposits show on **`/orders`** (Pre-order filter). `/store-preorders` shows all-time **eligible** (not cancelled/voided) **units** in the header, with the distinct order count smaller underneath, and the same stack per kit. The header units link to `/orders?preorder=only&status=eligible` with a wide from-date so the 7-day default does not hide them. Closing can be filtered to one date. A dedicated preorder-orders workbench (balance due, remaining cap) is still later.

## Remaining cap

Until customer orders exist, **remaining** equals **cap** while open, `0` when closed, and “No cap” when `cap_qty` is null. Operators can change cap on an open offer (empty field = no cap). Saving cap (or a bulk cap/deposit/price/closing change) pushes Shopify when the SKU is already mirrored.

## Related

- Table: `store_preorders`
- Services: `StorePreorderOpenService`, `StorePreorderCloseService`, `StorePreorderExpireService`, `StorePreorderQueryService`, `StorePreorderOrderStatsService`, `StorePreorderEtaLookup`, `StorePreorderProductEnsureService`, `StorePreorderBulkUpdateService`, `StorePreorderDeleteService`, `StorePreorderShopifyShelfSyncService`, `StorePreorderPlamodPickListImageAttachService`, `StorePreorderPlamodImagesOnlyCleanupService`, `StorePreorderPlamodDescriptionSyncService`
- CLI: `php artisan shopify:store-preorders-shelf-sync` — keep current offers (open or closed), retag ERP offers that lost `sp:store-preorder`, untag leftovers when the offer is gone, delete Shopify product otherwise
- CLI: `php artisan shopify:store-preorders-collection-reorder` — set `/collections/pre-orders` to manual sort and reorder by close date (also queued after open/close/push/shelf-sync/delete and closing-date bulk edits)
- CLI: `php artisan store-preorders:close-expired` — close **open** offers whose `window_ends_on` is before today (America/Toronto) using `StorePreorderCloseService`; pushes Shopify first so inventory is 0. Scheduled daily **00:15** America/Toronto.
- CLI: `php artisan store-preorders:refresh-missing-photos` — attach real Plamod pick-list images for **open and closed** offers with no real Shopify-enabled images (rejects “No image” placeholders), queue a Shopify images-only push (does not rewrite tags), and recrawl the Plamod PDP for leftovers (scheduled daily 07:00 America/Toronto)
- CLI: `php artisan store-preorders:sync-plamod-descriptions --push` — scrape Plamod **full** PDP descriptions onto existing store-preorder products (skips `OVS-*` manual offers) and push listing info to Shopify
- CLI: `php artisan store-preorders:keep-plamod-images` — delete HLJ assets from store-preorder products and queue a Shopify **images-only** push for those SKUs (`--dry-run` counts only). Recrawl / Get product info skip HLJ, Bandai, Gundam Planet, Newtype, and Gundam Hangar image attach on store-preorder products.

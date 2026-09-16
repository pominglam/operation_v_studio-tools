# Store preorders (v1 admin slice)

## Goal

Let operators open customer **store preorders** from the Plamod pick list **or Add offer** (other shops) and close them later. ERP product is created only when an offer is opened.

## Terms

- **Plamod preorders** (`/preorders`): distributor buy-side snapshot.
- **Store preorder** (ERP): offer on a normal product.
- **Pre-order** (storefront, later): customer-facing label.

## v1 includes

- Rename nav **Preorders** → **Plamod preorders**; add **Store preorders**.
- Pick-list checkboxes + **Open & push to Shopify** confirm (per kit: sell $, landed from PO cost + restock shipping estimate, live sell/landed multiplier, deposit %, cap, closing pre-filled as Plamod due − 1 day). Confirm queues Shopify publish immediately; photos update when the Plamod crawl finishes.
- `/store-preorders` **Add offer** for non-Plamod kits: optional URL crawl (host registry; Fuwa Fuwa first), or manual name/description/photos. SKU prefills `OVS-{slug}`. Operator sets sell $. Unique SKU check on save.
- Maintenance **OPV catalog margin** (1.5× estimated landed + default deposit).
- Store preorders list: Open/Closed, editable cap, deposit, closing, Plamod ETA; **Close** confirm (qty 0, no product delete); daily **00:15 America/Toronto** auto-close of open offers whose closing date is before today (same close path, Shopify qty 0 first; blank closing dates stay open); **Delete** confirm (remove offer; delete unused ERP product); bulk edit (cap / deposit / sell $ / closing) and bulk delete of selected rows.
- Filters: already opened vs not; closing-soon sort.
- Manufacturer include/exclude panel collapsed (not used by kits-hub sync).
- Sync status copy names kits hub New Preorders + Offer Sheets.

## Storefront (this slice)

- Products grid hides open store preorders by default (`store_preorder=exclude`).
- Opening a store preorder queues a Shopify listing push (tag `sp:store-preorder`, deposit price) and Plamod photos onto the ERP product (pick-list file when present; otherwise a Plamod PDP zip crawl). HLJ / other catalog scrapers do not attach images to store-preorder products. Plamod hub/PDP **“No image”** graphics are not treated as product photos. When real images exist, Shopify is updated (images-only on later photo follow-up; image-only `productSet` does not send tags). Open **and closed** offers that still have no real images are recrawled from Plamod once a day.
- **Push to store** remains a retry. Checkout price = deposit; qty = remaining cap when capped, or untracked / continue selling when there is no cap; tag `sp:store-preorder` only. Metafields carry full price, deposit, remaining, closing date, and Plamod ETA. Cap edits push Shopify when the product is already mirrored.
- Deleting an offer on `/store-preorders` removes `sp:store-preorder` on Shopify when the ERP product is kept. If the ERP product is also gone and the SKU has no Shopify order, the Shopify product is deleted. Closed offers stay tagged, including after a later catalog / model-kit Shopify upsert.
- Customer shelf: `/collections/pre-orders`. Default sort is **Close date** (soonest first; Shopify collection positions, 16 per page). Main nav **Preorders** sits before Miscellaneous. Shows full catalog price like a normal product, then “Deposit $X by {close date}” and “ETA {date}” when Plamod has one. Homepage featured-collection cards show catalog price only. PDP shows a **Pre-order** label under the title. Button is **Pre-order** until the close date, then **Preorder closed** (not addable). Cap is not shown.
- Shopify line: title suffix `(PO)`, line properties `(PO)` / `Pre-order` (deposit), order tag `preorder` after sync. ERP `/orders` badges matching lines.

## Later

- `/orders` **Pre-order → Has pre-order item** lists sales with at least one store-preorder line. A dedicated balance-due workbench is still later.
- Live Rise theme publish of the Preorders nav item (AI Dev has it).
- Shopify selling plans / automatic balance invoices.

## Still out

- PO receive / holds / flip to normal stock.

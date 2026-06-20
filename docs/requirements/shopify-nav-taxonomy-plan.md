# Shopify nav & taxonomy rework — planning summary

**Status:** Approved direction, **not implemented**.  
**Last updated:** 2026-06-07  
**Detailed spec:** [tools-supplies-classification.md](./tools-supplies-classification.md) (navbar, per-page filters, `ts:*` tags, cleanup queue)  
**Related stores:** ERP (`pricing-tool`) → Shopify (`operationvstudio.com` / theme `ovs-shopify-theme`)

This doc captures where planning left off for merging workshop categories, flattening nav, and using tags + filters. **Getting started content pages are a separate task.**

---

## 1. ERP product taxonomy (today)

Products use **five free-text columns** (no strict enum):

| Field | Role | Notes |
| --- | --- | --- |
| `main_type` | Coarse bucket | UI defaults: `model kit`, `tools`, `paints`, `supplies`; DB also has `misc` |
| `type` | Fine line | e.g. `HG`, `MG`, `PAINT`, `MARKERS`, `Panel liner`, `NIPPER` |
| `grade` | Optional | Often overlaps `type` |
| `scale` | Optional | e.g. `1/144`, `non` |
| `series` | Optional | Franchise / line names |

**Shopify push today** (`ProductExportService`):

- `productType` ← ERP `type`
- `tags` ← `main_type` + `type` (+ `latest arrival` when flagged)
- `grade`, `scale`, `series` are **not** pushed to Shopify

**Paints & markers in ERP (~167 SKUs):** mostly `main_type = supplies`, `type` in `PAINT` (87), `MARKERS` (63), `Panel liner` (16); vendors Stedi / Dspiae. Some rows misclassified (e.g. airbrush needles as `PAINT`).

---

## 2. Shopify storefront (today)

- **Navbar:** Shopify Admin → Navigation → **`main-menu`** (not in git). Theme references it in `sections/header-group.json`.
- **Live top-level (4):** Model Kits · Paints & Markers · Tools · Miscellaneous — deep dropdowns by **brand** and SKU families (too many layers).
- **Collections:** Many handles (e.g. `high-grade-hg`, `markers`, `paint`); homepage uses `latest-arrivals`, `homepage-featured`, etc.
- **Filters:** Dawn theme supports facets; live paint/marker collections often show only **Availability** + **Price** until more tags/metafields are configured in **Search & Discovery**.
- **ERP does not sync menus**; `shopify_collections` mirror was empty locally until `shopify:sync collections` is run.

---

## 3. Problems we agreed on

1. **Brand-heavy nav** (Stedi → XG → …) — wrong axis for customers.
2. **Too many filter groups / options** (competitor sites) — overwhelming for beginners.
3. **Navbar crowded** — need room for beginner-oriented links.
4. **ERP `supplies` + `type`** too coarse for storefront filters (surfacer vs solid, airbrush vs hand, etc.).

---

## 4. Target top-level navigation

```text
Model Kits ▾ | Tools & Supplies ▾ | Miscellaneous | Getting started ▾
```

| Item | Purpose |
| --- | --- |
| **Model kits** | Unchanged focus; kit-specific filters (grade, in stock) — not workshop products |
| **Tools & Supplies** | **Merge** current *Paints & Markers* + *Tools* into one area |
| **Miscellaneous** | Keep as catch-all |
| **Getting started** | Dropdown only in this task; **content pages later** |

**Rejected label:** “Workshop” (unclear to customers). **Chosen:** **Tools & Supplies**.

### Tools & Supplies — shallow dropdown (links, not brand trees)

**Authoritative list:** [tools-supplies-classification.md §2](./tools-supplies-classification.md#2-tools--supplies-dropdown-new) (updated 2026-05-25 — **clarity over brevity**: named tool shelves, not one “Tools” page).

Summary child links:

- All tools & supplies
- **Brushes** · **Drills & bits** · **Tweezers** · **Scribing tools** · **Adhesives**
- Nippers & knives · Sanding · Tapes · Markers · Paints · Decals · Airbrush
- **Other tools & supplies** (`workshop-misc` — orphans only; not site Miscellaneous)

### Getting started — sub-links (separate content task)

Parent: **Getting started**

1. **New to the shop** — about Operation V, ordering, shipping, where to start
2. **New to model kit building** — grades, first kit, starter supplies
3. **New to airbrushing** — first purchases, link to airbrush-tagged collections

Each page: **content + curated links** to collections with **filters in the URL**. **Not in scope** for the taxonomy/nav implementation task.

---

## 5. Collection & filter strategy

### One primary landing

- Single merged collection (e.g. `tools-and-supplies` or similar handle TBD).
- **Flat nav** — customers click Tools & Supplies and see everything; narrow with **few filters** or **horizontal chips**.

### Filters work with **tags** (and/or metafields)

Configure in Shopify **Search & Discovery**. Theme (`facets.liquid`) renders what Shopify exposes.

**Keep filter noise low (beginner-friendly):**

| Guideline | Target |
| --- | --- |
| Filter groups per collection | **3–4 max** |
| Visible options per group | **~4–6**; use broad buckets, not long “See more” lists |
| ERP detail (`series`, exact SKU line) | Staff/ERP only unless rolled up to a short customer tag |

### Suggested filter dimensions (Tools & Supplies)

Priority order; not all need to show on every sub-view:

| Filter | Example values |
| --- | --- |
| **Product** | Paint · Marker · Panel line · Thinner |
| **Tools vs supplies** | Tool · Supply |
| **Application** (paint-focused) | Airbrush paint · Hand paint |
| **Finish** | Solid · Metallic · Fluorescent · Clear |
| **Marker tip** (markers only) | Soft tip · Hard tip (audit catalog before exposing hard tip) |
| **Brand** | Optional, lower priority |

**Markers / panel line:** treat as **hand** application (can be automatic from product type).

**Thinners / cleaners:** airbrush workflow, supplies.

### Pre-filtered URLs

Shopify collection URLs can include filter query params so nav, chips, and Getting started pages reopen the same narrowed view, e.g.:

```text
/collections/tools-and-supplies?filter.p.tag=pm%3Amarker
```

Copy exact params from the storefront after tags exist. Use stable tag vocabulary (e.g. prefix `pm:`).

### Multiple collections

Products **can** belong to multiple smart collections if tags match. Prefer **one parent collection + chips/URLs** over many parallel menu trees.

---

## 6. Classification rules (ERP → tags) — draft

Plan **new structured tags** (or ERP fields) on push; avoid relying only on `supplies` + `PAINT`.

**Paint application:** split **airbrush paint** vs **hand paint** where merchandising is honest (user preference over “general” for all bottles).

**Finish (paint & marker where relevant):** solid, metallic, fluorescent, clear.

**Markers:** soft tip vs hard tip (catalog today is mostly “Soft Tipped” Dspiae).

**SKU-prefix hints (Stedi/Dspiae):** e.g. `XG-*` solid paint, `XPS-*` surfacer, `MKF-*` fluorescent marker, `MP-*` panel line, `MK-*` marker — to be finalized in a rules table before bulk classify.

**Cleanup:** move non-paint items (airbrush needles, etc.) out of `PAINT` / into `tools`.

**Long-term ERP:** prefer a dedicated column (e.g. `consumable_kind` / storefront tags) over overloading `series`.

---

## 7. Implementation task split

| Task | In scope |
| --- | --- |
| **Taxonomy + Tools & Supplies** | Tag schema, ERP classification/bulk update, merged collection, Shopify filters, flatten `main-menu` Tools & Supplies branch, product re-push, retire brand-deep menu children |
| **Getting started pages** | Three content pages, copy, in-page links to filtered collections — **later task** |
| **Model kits nav/filters** | Separate pass; keep simple (grade, in stock); avoid competitor-style Series + Scale + Brand + Product line stacks |

---

## 8. Explicitly not done yet

- [ ] No ERP schema/tag push changes
- [ ] No Shopify Admin menu/collection edits
- [ ] No Getting started page content
- [ ] No `shopify:sync collections` run documented in this plan
- [ ] No filter URL cookbook (tag → marketing link) — create during implementation

---

## 9. Other context (same project, different threads)

- **Hold qty** feature: implemented (`hold_qty`, push/pull formulas, products grid, PO push preview).
- **PC startup:** Windows scheduled tasks for Docker + Cloudflare tunnel; named tunnel via `~/.cloudflared/config.yml` (see `scripts/windows/README.md`).
- **Live ERP host:** `https://ovs.centredentairevsl.com` (tunnel + containers).

---

## 10. Open decisions before implementation

1. Final collection handle for merged area (`tools-and-supplies` vs reuse existing).
2. Exact tag prefix and allowed values (`pm:product:paint`, etc.).
3. Whether **Miscellaneous** stays fourth or is shortened to **Misc** for bar width.
4. Model kits filter set (separate doc pass).
5. Getting started page handles (`/pages/new-to-the-shop`, etc.) — names TBD when content task starts.

---

## References

- ERP types audit: conversation + DB counts (May 2026).
- Live nav scrape: `operationvstudio.com` (`main-menu` structure).
- Theme: `ovs-shopify-theme/sections/header-group.json`, `snippets/facets.liquid`.
- Shopify tags: `app/Services/Products/ProductExportService.php` (`shopifyTagsForProduct`).
- Filters: Shopify Admin → Search & Discovery.

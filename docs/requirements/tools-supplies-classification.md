# Tools & Supplies — classification rules & collection plan

**Status:** Phases 2–9 **implemented** (storefront nav live). Phase 10 optional.  
**Last updated:** 2026-05-25  
**Parent plan:** [shopify-nav-taxonomy-plan.md](./shopify-nav-taxonomy-plan.md)  
**Stores:** ERP `pricing-tool` → Shopify `operationvstudio.com` / `ovs-shopify-theme`

Use this doc to resume taxonomy, nav, tags, and per-collection filters. **Getting started content pages** remain a separate task.

---

## 1. Site navbar (target)

```text
┌────────────────────────────────────────────────────────────────────────────┐
│  MODEL KITS ▾  |  TOOLS & SUPPLIES ▾  |  MISCELLANEOUS  |  GETTING STARTED ▾ │
└────────────────────────────────────────────────────────────────────────────┘
```

**Getting started ▾** (content pages — later task): New to the shop · New to model kit building · New to airbrushing

**Miscellaneous** — unchanged for now. **Not** reclassifying action bases / keychains / option parts in this pass (see §8).

---

## 2. Tools & Supplies dropdown (new)

**Design principle — clarity over brevity:** Each menu line should match one thing a shopper is looking for (e.g. “Brushes”, not “Tools → filter to brushes”). Prefer an extra nav item over hiding unlike products behind filters. The **All tools & supplies** hub is for browsers; the dropdown is for intent.

```text
TOOLS & SUPPLIES ▾
┌─────────────────────────────┐
│  Adhesives … Tweezers       │  A–Z (13 named shelves)
│  ─────────────────────────  │
│  All tools & supplies       │  → /collections/tools-and-supplies
│  Other                      │  → /collections/workshop-misc
└─────────────────────────────┘
```

Named shelves (A–Z): Adhesives, Airbrush, Brushes, Decals, Drills & bits, Markers, Nippers & knives, Panel liners, Paints, Sanding, Scribing tools, Tapes, Tweezers, Weathering. **All** and **Other** are pinned at the bottom of the dropdown (slightly muted + divider in theme).

> **Not the same as top-level Miscellaneous** — action bases, keychains, and kit accessories stay under site **Miscellaneous** (§4.5). **Other tools & supplies** is workshop oddments only (mis-tags, low-volume tools, items that do not fit a named shelf yet).

### What each page owns


| Page                      | Collection handle      | Primary ERP signals today                         | ~SKU scale (order of magnitude) |
| ------------------------- | ---------------------- | ------------------------------------------------- | ------------------------------- |
| **All**                   | `tools-and-supplies`   | Union of all `ts:dept:*` below                    | ~400+ workshop-adjacent         |
| **Brushes**               | `brushes`              | `main_type=tools`, brush / anti-static rows       | Small                           |
| **Drills & bits**         | `drills`               | Hand drills, bits, pin vices                      | Small                           |
| **Tweezers**              | `tweezers`             | Tweezer / pickup tools                            | Small                           |
| **Scribing tools**        | `scribing-tools`       | Scriber handles, pushers, scribing consumables    | Small                           |
| **Adhesives**             | `adhesives`            | Cement, glue, reinforcement (e.g. ETC, MG)        | Small                           |
| **Nippers & knives**      | `nippers-and-knives`   | `type` NIPPER, knives, blades, pen knives         | ~35+                            |
| **Sanding**               | `sanding`              | `type` SANDING (supplies + tools)                 | ~58                             |
| **Tapes**                 | `tapes`                | Masking (`MT-*`) and scribing tape (`MS-03`, `MS-06`) | 8 in pilot                  |
| **Markers**               | `markers`              | `type` MARKERS (excl. MS-58)                      | ~70                             |
| **Paints**                | `paints`               | PAINT, panel line liquids, surfacer, top coat, bundles | ~100+                           |
| **Panel liners**          | `panel-liners`         | Accent pens, wiper tools + liquid panel liners (dual) | ~20                             |
| **Decals**                | `decals`               | Decal softeners now; sheets later                 | ~2 now; growing                 |
| **Airbrush**              | `airbrush`             | Airbrush units, needles, airbrush-only supplies   | Small today                     |
| **Weathering**            | `weathering`           | `type` WEATHERING (Stedi MP-5x weathering liquids) | 4 (Stedi pilot)                |
| **Other tools & supplies**| `workshop-misc`        | Orphans, mis-tags, unclassified workshop SKUs     | Very small (grows slowly)       |


**Airbrush hardware:** listed on **Airbrush** only — **not** on Brushes / Drills / Other.

**Scribing tape** (`MS-03`, `MS-06`) stays on **Tapes**, not **Scribing tools** (tape vs tool is a different shopper intent).

**Rejected (2026-05-25):** Single **Tools** page with tool-type filters — harder for beginners than named shelves.

---

## 3. Per-collection filters (beginner-friendly: 3–4 groups max)

Configure in Shopify **Search & Discovery** per collection. Tags use prefix `**ts:`** (tools & supplies) unless noted.

### 3.1 All tools & supplies

| Filter / nav           | Values                                                                                                                                 |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| **Category**           | Sidebar links to each §2 shelf (not checkboxes) — Brushes · Drills & bits · … · Other tools & supplies                                    |
| **Availability**       | In stock                                                                                                                               |

Shelf pages show breadcrumb under the title: **Tools & supplies › {Shelf}** linking back to the hub.

No paint-type, grit, or department checkbox filters on the hub.

---

### 3.2 Brushes

Collection: `brushes`. One shelf — no “tool type” filter required.

| Filter           | Values *(expose only when ≥2 options exist in catalog)* |
| ---------------- | ------------------------------------------------------- |
| **Brush type**   | Hand brush · Anti-static · Detail / fine *(TBD at QA)*  |
| **Brand**        | Optional, collapsed                                     |

**Tag examples:** `ts:dept:brushes`, `ts:brush:type:anti-static`

---

### 3.3 Drills & bits

Collection: `drills`.

| Filter        | Values *(TBD at QA)*                          |
| ------------- | --------------------------------------------- |
| **Type**      | Hand drill · Bits · Pin vice / rotary handle  |
| **Brand**     | Optional                                      |

**Tag examples:** `ts:dept:drills`, `ts:drill:type:bit`

---

### 3.4 Tweezers

Collection: `tweezers`.

**Shopify/ERP titles (Option A):** Stedi tweezer SKUs use two product lines in the title so straight-tip variants are distinguishable on the grid:

| Line | SKU range | Title pattern |
| ---- | --------- | --------------- |
| Ultra-Precision | `MS-11`–`MS-17` (except gaps) | `Stedi Ultra-Precision Tweezers ({tip})` |
| Thick-Wall | `MS-160`–`MS-163` | `Stedi Thick-Wall Tweezers ({tip})` |

Canonical map lives in `StediTweezerTitleResolver`; apply via `php artisan products:rename-stedi-tweezers --apply`.

| Filter        | Values *(TBD at QA)*                |
| ------------- | ----------------------------------- |
| **Line**      | Ultra-Precision · Thick-Wall        |
| **Style**     | Straight · Curved · Flat · Pointed  |
| **Brand**     | Optional                            |

**Tag examples:** `ts:dept:tweezers`, `ts:tweezer:line:ultra-precision`, `ts:tweezer:style:curved`

---

### 3.5 Scribing tools

Collection: `scribing-tools`. Scriber handles, pushers, guides, scribing **consumables** (not tape — tape is §3.10).

| Filter        | Values                            |
| ------------- | --------------------------------- |
| **Type**      | Scriber & Pusher · Handle · Others |
| **Brand**     | Optional                          |

**Tag examples:** `ts:dept:scribing`, `ts:scribing:type:scriber` (grid maps scriber/pusher → filter group `scriber-pusher`; needle/scraper → `others`)

**Storefront filter URL:** `?ovs_scribing_type=scriber-pusher,handle` (comma-separated multiselect). Scribing tape (`MS-03`, `MS-06`) lives under **Tapes**, not this collection. Needle scribers (`MS-23`) belong here under **Others**. Push knives (`MS-27`) belong under **Nippers & knives**.

---

### 3.6 Adhesives

Collection: `adhesives`. Cements and assembly glues with a clear product type.

| Filter        | Values *(TBD at QA)*                    |
| ------------- | --------------------------------------- |
| **Type**      | Cement · Reinforcement · Putty / filler |
| **Brand**     | Optional                                |

**Tag examples:** `ts:dept:adhesives`, `ts:adhesive:type:cement`

---

### 3.7 Other tools & supplies (workshop misc)

Collection: `workshop-misc`. Catch-all for orphans and low-volume workshop SKUs until ERP/`ts:*` rules assign a named shelf.

| Filter           | Values    |
| ---------------- | --------- |
| **Availability** | In stock  |

No type filters until the page grows enough to split SKUs into §3.2–3.6.

**Tag examples:** `ts:dept:workshop-misc`

**Include:** MS-58 (joint reinforcement), MP-01B/R / MP-02B/R (painting-tool pens), future mis-tags.

---

### 3.8 Nippers & knives


| Filter           | Values                                                  |
| ---------------- | ------------------------------------------------------- |
| **Category**     | Nippers · Knives · Blades & refills                     |
| **Nipper style** | Single edge · Double edge · Beginner *(only if tagged)* |
| **Knife style**  | Pen knife · Utility / OLFA · Ceramic *(if stocked)*     |


Optional: **Brand** (GodHand, Stedi, Dspiae, OLFA, …).

**Tag examples:** `ts:cut:nipper`, `ts:cut:knife`, `ts:cut:blade`, `ts:cut:nipper-beginner`

---

### 3.9 Sanding

~58 SKUs (boards/plates + sponge/adhesive consumables).


| Filter                        | Values                                                                       |
| ----------------------------- | ---------------------------------------------------------------------------- |
| **Type**                      | Adhesive sheets · Sticks & sponges · Glass polishing files · Boards & plates |
| **Grit**                      | Coarse (320–400) · Medium (600–800) · Fine (1000–1200) · Polish (1500–2000+) |
| **Width** *(optional, max 4)* | Narrow (2–3 mm) · Standard (20–25 mm) · Mixed pack                           |


Do **not** expose every grit as its own checkbox. Bucket into 4 groups.

**Tag examples:** `ts:sand:type:sheet`, `ts:sand:type:stick-sponge`, `ts:sand:grit:coarse`, `ts:sand:width:narrow`

**Storefront filter URLs (theme):** `?ovs_sand_type=sheet,stick-sponge` and `?ovs_grit=coarse,medium` (comma-separated multiselect; AND across groups). Pilot collections load all products on one page (250 cap) so client-side filters apply to the full catalog.

---

### 3.10 Tapes

Masking tape (`MT-02` … `MT-20`) and scribing tape (`MS-03`, `MS-06`) in pilot.


| Filter        | Values                                                  |
| ------------- | ------------------------------------------------------- |
| **Tape type** | Masking · Scribing                                      |
| **Width**     | 2 mm · 3 mm · 5 mm · 6 mm · 10 mm · 15 mm · 20 mm |


Six masking widths plus 6 mm scribing; 3 mm appears on both families.

**Tag examples:** `ts:tape:masking`, `ts:tape:scribing`, `ts:tape:width:10`

**Storefront filter URLs (theme pilot):** `?ovs_type=masking,scribing` and `?ovs_width=3,6` (comma-separated multiselect; AND across groups)

---

### 3.11 Markers


| Filter          | Values                                                       |
| --------------- | ------------------------------------------------------------ |
| **Marker type** | Solid colors · Metallic · Fluorescent · Clear *(clear hidden until SKUs exist)* |
| **Tip**         | Soft tip · Hard tip                                                           |
| **Brand**       | Dspiae · Stedi                                                                |


**Tag examples:** `ts:marker:type:solid`, `ts:marker:tip:soft`, `ts:marker:brand:dspiae`

**Storefront filter URLs (theme):** `?ovs_marker_brand=dspiae,stedi`, `?ovs_marker_type=`, `?ovs_marker_tip=` (comma-separated multiselect; AND across groups)

---

### 3.12 Paints


| Filter          | Values                                                          |
| --------------- | --------------------------------------------------------------- |
| **Product**     | Paint · Surfacer · Top coat · Panel line · Thinner · **Bundle** |
| **Application** | Airbrush paint · Hand paint                                     |
| **Paint type**  | Solid · Metallic · Fluorescent · Clear                          |


**Bundles** (e.g. `OVSP-00001` full DSPIAE set) live here for beginners until a store-wide **Bundles** section exists later.

Surfacer / top coat are **Product**, not paint type. Paint type applies mainly to color **Paint** SKUs.

**Tag examples:** `ts:paint:product:surfacer`, `ts:paint:product:bundle`, `ts:paint:app:airbrush`, `ts:paint:type:solid`

---

### 3.13 Panel liners

Dedicated shelf for panel-lining workflow — pens, wipers, and liquid panel-liner bottles. Liquid bottles **also** stay on **Paints** (`Product → Panel line` filter unchanged).

| Filter      | Values                          |
| ----------- | ------------------------------- |
| **Product** | Tools · Panel liner paints      |
| **Type**    | Normal · Fluorescent            |

**Tools:** seepage line wiper pens (`MP-02*`, `MP-03*`). **Panel liner paints:** accent pens (`MP-01*`) and liquid panel-liner bottles (`MP-10+`, `type` Panel liner).

**Tag examples:** `ts:dept:panel-liners`, `ts:panel-liner:kind:tool`, `ts:panel-liner:kind:paint`, `ts:panel-liner:type:normal`, `ts:panel-liner:type:fluorescent`, `ts:paint:product:panel-line` (liquids on Paints dept)

Smart collection rule (OR): `ts:dept:panel-liners` **or** `ts:paint:product:panel-line`.

---

### 3.14 Decals

Decal softeners (Dspiae) and water-slide decal sheets. Generic kit-line sheets without a known manufacturer brand use **Unclassified** in the Brand filter.

| Filter      | Values                                                                 |
| ----------- | ---------------------------------------------------------------------- |
| **Product** | Water decal · Softener / setter                                        |
| **Brand**   | Dspiae (softeners; water decals only when **Dspiae** appears in the product title) · **Unclassified** (generic kit-line water decals) |

**Tag examples:** `ts:decal:softener`, `ts:decal:sheet`, `ts:decal:brand:dspiae`, `ts:decal:brand:unclassified`

**Storefront filter URLs:** `?ovs_decal_product=sheet,softener` and `?ovs_decal_brand=dspiae,unclassified` (comma-separated multiselect; AND across groups)

---

### 3.15 Airbrush


| Filter                      | Values                                      |
| --------------------------- | ------------------------------------------- |
| **Category**                | Airbrush tool · Supplies & parts            |
| **Product** *(when supply)* | Paint · Thinner / cleaner · Needles / parts *(future)* |

Airbrush **paint** also appears on **Paints** with `Application: Airbrush`; dual tags OK.

**Tag examples:** `ts:dept:airbrush`, `ts:airbrush:role:tool`, `ts:airbrush:role:supply`

**Storefront filter URL:** `?ovs_airbrush_role=tool,supply` (comma-separated multiselect)

---

## 4. Classification rules (decided — implement later)

### 4.1 ERP → storefront mapping (target)


| ERP field                  | Storefront use                                                                                     |
| -------------------------- | -------------------------------------------------------------------------------------------------- |
| `main_type`                | Staff + coarse grouping; migrate workshop rows toward `supplies` / `tools` / `paints` consistently |
| `type`                     | Keep for legacy; map to `ts:`* tags on Shopify push                                                |
| `grade`, `scale`, `series` | ERP only for now (except decal sheets later may use `scale`)                                       |
| **New (planned)**          | `storefront_tags` or classify service output → Shopify **tags** driving filters                    |


### 4.2 Department assignment (each product exactly one primary department tag)

One `ts:dept:*` per product. Tool families use **separate department tags** (not a single `ts:dept:tools`).


| Department tag            | Collection handle      | Include                                                       |
| ------------------------- | ---------------------- | ------------------------------------------------------------- |
| `ts:dept:brushes`         | `brushes`              | Hand brushes, anti-static brushes                             |
| `ts:dept:drills`          | `drills`               | Hand drills, bits, pin vices                                  |
| `ts:dept:tweezers`        | `tweezers`             | Tweezers, pickup tools                                        |
| `ts:dept:scribing`        | `scribing-tools`       | Scriber handles, pushers, scribing consumables                |
| `ts:dept:adhesives`       | `adhesives`            | Cement, glue, reinforcement (e.g. ETC-01/02, MG-01/02)        |
| `ts:dept:workshop-misc`   | `workshop-misc`        | Orphans / mis-tags / unclassified workshop (see §4.7)         |
| `ts:dept:cutting`         | `nippers-and-knives`   | Nippers, knives, blades                                       |
| `ts:dept:sanding`         | `sanding`              | All SANDING rows (tool + consumable)                          |
| `ts:dept:tapes`           | `tapes`                | Masking and scribing **tape**                                 |
| `ts:dept:markers`         | `markers`              | MARKERS (excl. MS-58)                                         |
| `ts:dept:paints`          | `paints`               | PAINT, panel line, surfacer, top coat, thinner, paint bundles |
| `ts:dept:decals`          | `decals`               | Decal softeners; sheets when stocked                          |
| `ts:dept:airbrush`        | `airbrush`             | Airbrush units, needles, airbrush-only parts                    |


**Deprecated:** `ts:dept:tools` — do not use after Phase 7; migrate any pilot usage to the rows above.


### 4.3 SKU / description hints (draft — refine at implementation)


| Pattern                                      | Department            | Notes                                            |
| -------------------------------------------- | --------------------- | ------------------------------------------------ |
| `MT-*`                                       | Tapes                 | Masking; width from SKU suffix                   |
| `MS-03`, `MS-06` (description contains Tape) | Tapes                 | Scribing **tape**; width from SKU suffix         |
| `ETC-01/02`, `MG-01/02`                      | Adhesives             | Cement / glue                                    |
| `MS-58`                                      | Workshop misc         | Joint reinforcement — mis-tagged MARKERS         |
| `MP-01B/R`, `MP-02B/R`                       | Workshop misc         | Painting-tool pens (not panel line liquids)      |
| `ETC-03/04`                                  | Decals                | Softeners                                        |
| `MS-B*`, `MS-C*`, `MS-D*`, `MS-AT*`          | Sanding    | Consumables                                      |
| `MS-D2`, `MS-D4`, `MS-D15`                   | Sanding    | Boards/plates (tool form)                        |
| `MK-*`, `MKF-*`, `DMM-*`, `MS-5*` markers    | Markers    | Fix MS-58 (joint reinforcement) — **not** marker |
| `XG-`*                                       | Paints     | Color paint                                      |
| `XPS-*`                                      | Paints     | Surfacer                                         |
| topcoat / top coat in title                  | Paints     | Top coat product                                 |
| `MP-*` panel liner                           | Paints     | Panel line product                               |
| `OVSP-*`                                     | Paints     | Bundle                                           |
| `PT-AB`, `GHAD-*`                            | Airbrush   | Hardware                                         |
| `AB-D*`, needles                             | Airbrush   | Parts/supplies                                   |
| `MS-10*`, `MS-11*`, `MS-104`, `GH-PN`, `PN-` | Cutting    | Nippers                                          |
| `MS-22`, `AK-*`, `OLFA`, pen knife           | Cutting    | Knives                                           |


### 4.4 Shopify push (future change)

Replace naive `main_type` + `type` tags with curated `ts:*` tag set + keep `productType` human-readable where helpful.

### 4.5 Explicitly deferred (do not classify in this pass)


| Group                                  | ERP today              | Decision                                                     |
| -------------------------------------- | ---------------------- | ------------------------------------------------------------ |
| Action bases                           | `misc` / `ACTION BASE` | Stay **Miscellaneous** (or model kits links) — revisit later |
| Keychains                              | `misc` / `KEYCHAIN`    | Stay **Miscellaneous**                                       |
| Option parts / hands / lenses          | `misc` / `ACCESSORIES` | Stay **Miscellaneous**                                       |
| Model kit accessories misfiled as kits | `model kit` / `Others` | Separate kit taxonomy pass                                   |


### 4.6 Follow-up: category labels & filter order (after pilot)

**Task tracker:** `storefront_category_filter_order_review` in `.cursor/task-tracker.json`

**When:** After Phases 2–8 collections are live and reviewed; **before** Phase 9 nav cutover (or immediately after if nav waits).

**Scope:**

1. **Per-collection filter UX** (theme `ovs-*-collection-filters` snippets) — for each pilot page, confirm with operator:
   - **Group labels** (e.g. paints first group is **Type**, not “Product”; sanding uses **Type** + **Grit**)
   - **Option list** — add/remove values based on real catalog (e.g. paints: no Bundle/Clear; cutting: Category + Style)
   - **Display order** — order of filter groups in sidebar/drawer and order of checkboxes within each group
2. **Tools & Supplies nav order** — §2 dropdown sequence finalized for shopper clarity (named tool shelves before consumables). Revisit only if SKU counts make a shelf too thin to keep listed.
3. **ERP `main_type` / `type`** — out of scope for this task; storefront `ts:*` tags remain source of truth until a separate ERP normalization pass.

**Do not implement** until operator approves a sorted spec per collection (update §3 tables + theme snippets in one PR).

### 4.7 Orphan workshop SKUs (workshop misc)

Some ERP rows are mis-tagged (`type=MARKERS` for glue, `type=PAINT` for airbrush needles) or do not fit a named shelf yet.

| SKU | What it is | Storefront today | Target (Phase 7+) |
| --- | ---------- | ---------------- | ----------------- |
| **MS-58** | Joint reinforcement glue | No `ts:*` department | **`workshop-misc`** (or **Adhesives** after operator confirms label) |
| **MP-01B/R, MP-02B/R** | Accent / wiper pens | `tools` legacy tags only | **`workshop-misc`** until a **Painting tools** shelf is justified by SKU count |

**Decision (2026-05-25):** Use **`/collections/workshop-misc`** (“**Other tools & supplies**” in nav) for orphans — **not** top-level site **Miscellaneous** (kit accessories, keychains, etc. stay there per §4.5).

When a misc SKU grows a family (e.g. several reinforcement glues), reclassify to **Adhesives** and remove from misc.

Phase 6 excludes MS-58 from markers; misc collection is created in Phase 7.

### 4.8 Filter regression manifest & e2e

**Source of truth:** `ovs-shopify-theme/docs/storefront-ts-collection-filters.manifest.json` — every `ovs-*-collection-filters` snippet must be registered with `toggleCases` (desktop check→uncheck) and `mobileSmoke` (drawer sync).

**Verify (from `pricing-tool`):**

- `php artisan shopify:storefront-collection-filters-manifest-verify` — fails if a new snippet/handle is missing from the manifest
- `npm run test:e2e:storefront-filters` — Playwright reads the manifest (no hardcoded case list in the spec)

When adding a new collection shelf with OVS checkbox filters, update the manifest in the same change set as the theme snippets.

---

## 5. Data cleanup queue (§8 — what they are & target classification)

These are **not** “probably not tools & supplies” — they **are** workshop items that need **reclassification** before tags/collections work.


| SKU / row                                        | What it is                      | Today                  | Should be                                           |
| ------------------------------------------------ | ------------------------------- | ---------------------- | --------------------------------------------------- |
| **PT-AB**                                        | DSPIAE wash-free airbrush       | `tools` / `PAINT`      | **Airbrush** · tool                                 |
| **AB-D03, AB-D05**                               | Airbrush needles (0.3 / 0.5 mm) | `supplies` / `PAINT`   | **Airbrush** · supply/part                          |
| **MS-B50**                                       | Empty bottles + needles pack    | `supplies` / `PAINT`   | **Airbrush** · supply                               |
| **MS-58**                                        | Joint reinforcement glue        | `supplies` / `MARKERS` | **Other tools & supplies** (`workshop-misc`)        |
| **MP-01B/R, MP-02B/R**                           | Painting-tool pens              | `tools` / mis-tags     | **Other tools & supplies** (`workshop-misc`)        |
| **6× NIPPER** (null `main_type`, Chinese titles) | Nippers                         | empty `main_type`      | **Nippers & knives**                                |
| **6× TOOLS** (null `main_type`, pen knives)      | Knives / blades                 | empty `main_type`      | **Nippers & knives**                                |
| **MP-01…MP-05** (null `main_type`, Others)       | Likely panel liner misfiled     | empty `main_type`      | **Paints** · panel line (verify SKU)                |
| **E2E test knives**                              | Test data                       | `tools` / null type    | Exclude or fix in test DB                           |
| **MS-B400**                                      | Adhesive sandpaper              | `supplies` / `TOOLS`   | **Sanding** · consumable                            |
| **supplies / Scribing (3)**                      | Scribing consumables            | supplies               | **Scribing tools** (`scribing-tools`) — verify each SKU |


---

## 6. Paint bundles (beginners)

- Stay in **Paints** collection with **Product: Bundle** filter.
- Good for **Getting started** links later (“starter paint set”).
- Store-wide **Bundles** top-level section is a **future** task when multiple bundle types exist (kits + paint + tools).

---

## 7. Pre-filtered URL pattern (for nav + Getting started)

After tags exist, dropdown links copy from browser address bar, e.g.:

```text
/collections/sanding?filter.p.tag=ts%3Asand%3Aconsumable
/collections/paints?filter.p.tag=ts%3Apaint%3Aproduct%3Abundle
/collections/nippers-and-knives?filter.p.tag=ts%3Acut%3Anipper
```

Maintain a **filter URL cookbook** when implementing.

---

---

## 8.1 Single source of truth (classification service)

**Requirement:** Every code path that derives Shopify tags or storefront department/filters must call **one** classifier — no duplicated `if (sku starts with MT-)` logic in export, push, CSV, or Vue.

### Proposed layout

```text
App\Support\Products\Storefront\
├── StorefrontTag.php              # ts:* string constants
├── StorefrontClassification.php   # immutable result DTO
└── ProductStorefrontClassifier.php # pure: Product → StorefrontClassification

App\Services\Products\
└── ProductExportService            # delegates tag merge to classifier only
```

### `StorefrontClassification` (DTO)

| Field | Use |
| ----- | --- |
| `legacyTags` | `main_type`, `type`, `latest arrival` (unchanged during dual-write) |
| `storefrontTags` | `ts:*` tags (Phase 1+) |
| `shopifyTags` | **merged** deduped list actually pushed/exported |
| `department` | e.g. `tapes`, `decals`, `paints`, or `null` if out of scope |
| `warnings` | `['empty_main_type', 'misclassified_ms_58', …]` for staff review |

Classifier is **pure** (no DB, no HTTP): `Product` + optional config → DTO.

### Call sites (must use classifier)

| Consumer | Today | After |
| -------- | ----- | ----- |
| `ProductExportService::shopifyTagsListForProduct` | inline `shopifyTagsForProduct` | `classifier->classify($product)->shopifyTags` |
| `ShopifyProductUpsertFromErpService` | via export service | unchanged call chain |
| `ShopifyProductCreateFromErpService` | via export service | unchanged call chain |
| Shopify CSV export (`shopifyRow`) | via export service | unchanged call chain |
| **New:** API preview | — | `GET …/storefront-classification` or field on product resource |
| **New:** Web UI | — | read-only column / drawer from API (no TS duplication) |
| **New:** Artisan dry-run | — | `products:storefront-classify --dry-run` |

`ShopifyLatestArrivalTagRemoverService` keeps using `ProductExportService::LATEST_ARRIVAL_TAG` constant; classifier imports the same constant.

### Phase 1 scope gate

Config: `config/storefront_classification.php`

```php
'enabled_departments' => ['tapes', 'decals'], // Phase 1 pilot only
'dual_write_legacy_tags' => true,
```

**Implemented (Phase 1 code):**

- `App\Support\Products\Storefront\ProductStorefrontClassifier` — single source of truth
- `ProductExportService` — all Shopify tag export/push paths delegate here
- `ProductResource` — `storefront_classification` on API product rows
- `php artisan products:storefront-classify` — dry-run review command

Classifier emits `ts:*` tags **only** for pilot departments; all other products get legacy tags only until their phase.

### Tests

- Unit tests on `ProductStorefrontClassifier` with matrix: SKU, `main_type`, `type`, `description` → expected DTO.
- Feature test: export/push still includes legacy tags + pilot `ts:*` tags.
- Regression: empty `main_type` → no tags (existing behaviour).

### Why not duplicate in Vue/TS

Staff UI shows **API-computed** tags so what you see in the grid is exactly what Shopify receives on push.

---

## 8.2 Phased rollout (low impact)

Principles:

1. **Additive before subtractive** — new tags and collections exist alongside the old menu until the final cutover.
2. **Smallest catalogs first** — pilot on ~8 SKUs before ~100+ paint SKUs.
3. **Unlisted collections first** — verify filters on a direct URL before adding menu links.
4. **Dual tags during transition** — keep existing `main_type` / `type` Shopify tags; add `ts:*` tags until Phase 9 cleanup.
5. **Nav swap is last** — biggest customer-facing change; only after every child collection is verified.
6. **Rollback per phase** — each phase has an explicit undo (see table).

**Do not start Phase 9 until Phases 0–8 pass review.**

### Phase summary

| Phase | Name | Storefront impact | ~SKUs | Rollback |
| ----- | ---- | ----------------- | ----- | -------- |
| **0** | Foundation | None | 0 | N/A |
| **1** | Tag pipeline (dual-write) | None (tags only, same nav) | 0 pushed until 2 | Stop push; remove `ts:*` tags in bulk |
| **2** | Pilot: Tapes + Decals | Unlisted URLs only | ~8 | Delete 2 collections; strip pilot tags |
| **3** | Sanding | Unlisted URL | ~58 | Delete collection; revert sanding tags |
| **4** | Nippers & knives | Unlisted URL | ~35 | Delete collection; revert cutting tags + ERP fixes |
| **5** | Paints | Unlisted URL | ~100 | Delete collection; revert paint tags |
| **6** | Markers | Unlisted URL | ~71 | Delete collection; revert marker tags |
| **7** | Tool families + Airbrush + Misc | Unlisted URLs | small | Delete 8 collections; revert tags |
| **8** | All tools & supplies hub | Unlisted URL | union | Delete parent collection |
| **9** | **Nav cutover** | **High** — menu restructure | all | Restore `main-menu` export; old child links still work if collections kept |
| **10** | Cleanup & Getting started | Medium | — | Optional; content pages independent |

---

### Phase 0 — Foundation (ERP + docs only)

**Goal:** Everything needed to classify and push without touching the live menu.

| Step | Work |
| ---- | ---- |
| 0.1 | Finalize `ts:*` tag constants / classify service (read-only dry-run report) |
| 0.2 | Filter URL cookbook for each collection |
| 0.3 | Export current `main-menu` JSON (rollback snapshot) |
| 0.4 | List mis-tagged SKUs from §5 with owner sign-off |

**Exit criteria:** Dry-run classifies 100% of in-scope SKUs; no Shopify Admin changes.

**Impact:** Zero.

---

### Phase 1 — Tag pipeline (dual-write, no new pages)

**Goal:** New tags flow to Shopify; old nav and collections unchanged.

| Step | Work |
| ---- | ---- |
| 1.1 | Implement classify → `ts:*` tags on product export (keep legacy tags) |
| 1.2 | Fix §5 mis-tags in ERP for **pilot SKUs only** (MT-*, MS-03/06, ETC-03/04) |
| 1.3 | Re-push pilot SKUs; confirm `ts:*` tags visible in Shopify Admin |
| 1.4 | Confirm Search & Discovery can see new tags (no filters wired yet) |

**Exit criteria:** Pilot products show correct `ts:*` tags; product pages and menu unchanged.

**Impact:** None visible to shoppers (extra tags in Admin only).

**Rollback:** Revert export code; bulk-remove `ts:*` tags from pilot products.

---

### Phase 2 — Pilot: Tapes + Decals

**Goal:** Prove collection + filter pattern on the smallest catalogs.

| Step | Work |
| ---- | ---- |
| 2.1 | ERP classify + push all tape and decal SKUs |
| 2.2 | Create smart collections `tapes`, `decals` — **Online store: unlisted** |
| 2.3 | Search & Discovery: tape type + width; decal product type |
| 2.4 | QA direct URLs + filter chips; compare product counts to ERP |

**Exit criteria:** Counts match; filters behave; no menu links yet.

**Impact:** None unless URL is shared.

**Rollback:** Unpublish/delete collections; revert tags for those SKUs.

---

### Phase 3 — Sanding

| Step | Work |
| ---- | ---- |
| 3.1 | Classify all `SANDING` rows (tool vs consumable, grit buckets) |
| 3.2 | Unlisted collection `sanding` + Form / Grit filters |
| 3.3 | QA ~58 SKUs |

**Impact:** Unlisted only.

**Rollback:** Same as Phase 2.

---

### Phase 4 — Nippers & knives

| Step | Work |
| ---- | ---- |
| 4.1 | ERP fixes: empty `main_type` nippers/knives, MS-B400 → sanding if missed |
| 4.2 | Unlisted collection `nippers-and-knives` + category/style filters |
| 4.3 | QA counts vs audit |

**Impact:** Unlisted only.

**Rollback:** Same pattern.

---

### Phase 5 — Paints

| Step | Work |
| ---- | ---- |
| 5.1 | Classify paints, surfacer, top coat, panel line, thinner, **bundles** |
| 5.2 | Fix MS-58, MP-* null rows, airbrush paint dual-tags where needed |
| 5.3 | Unlisted collection `paints` + Product / Application / Paint type filters |
| 5.4 | QA bundle (`OVSP-00001`) under Product: Bundle |

**Impact:** Unlisted only. Old **Paints & Markers** menu still live.

**Rollback:** Same pattern.

---

### Phase 6 — Markers

| Step | Work |
| ---- | ---- |
| 6.1 | Classify markers (type + tip); confirm MS-58 not in set |
| 6.2 | Unlisted collection `markers` + Marker type / Tip filters |
| 6.3 | QA ~71 SKUs |

**Impact:** Unlisted only.

**Rollback:** Same pattern.

---

### Phase 7 — Tool families, Airbrush, Workshop misc

**Goal:** Named shopper shelves (§2) — no single “Tools” catch-all page.

| Step | Work |
| ---- | ---- |
| 7.1 | Classify **brushes**, **drills**, **tweezers**, **scribing tools**, **adhesives** → `ts:dept:brushes` … `ts:dept:adhesives` + optional subtype tags (§3.2–3.6) |
| 7.2 | Classify **workshop misc** (`ts:dept:workshop-misc`) — MS-58, MP-01B/R, MP-02B/R, other §4.7 orphans |
| 7.3 | Classify **airbrush** (PT-AB, needles, parts); fix §5 airbrush mis-tags |
| 7.4 | Unlisted collections: `brushes`, `drills`, `tweezers`, `scribing-tools`, `adhesives`, `workshop-misc`, `airbrush` + theme filters per §3 |
| 7.5 | QA: airbrush hardware only on **Airbrush**; scribing **tape** only on **Tapes**; misc count matches §4.7 |

**Impact:** Unlisted only.

**Rollback:** Delete Phase 7 collections; revert department tags for affected SKUs.

**Note:** Thin shelves (e.g. drills with &lt;5 SKUs) still get their own nav line — clarity over merging back into filters.

---

### Phase 8 — All tools & supplies (hub)

| Step | Work |
| ---- | ---- |
| 8.1 | Smart collection union of all `ts:dept:*` |
| 8.2 | Hub: **Category** sidebar links (§3.1) + Availability only; shelf pages: breadcrumb under title to hub |
| 8.3 | QA union count ≈ sum of departments (minus intentional overlaps) |

**Impact:** Unlisted only.

**Rollback:** Delete hub collection.

---

### Phase 9 — Nav cutover (highest impact)

**Goal:** Replace **Paints & Markers** + **Tools** with **Tools & Supplies** dropdown (§2).

| Step | Work |
| ---- | ---- |
| 9.1 | Publish all child collections (listed) |
| 9.2 | Publish `tools-and-supplies` hub |
| 9.3 | Update Shopify `main-menu`: new **Tools & Supplies** tree; remove deep brand/SKU children |
| 9.4 | Add top-level **Getting started** placeholder (empty or “coming soon” links) if desired |
| 9.5 | Smoke test every dropdown link + mobile menu |
| 9.6 | Optional: redirects from old collection handles if handles change |

**Exit criteria:** Full nav matches §1–2; no broken links; filters work from menu URLs.

**Impact:** **High** — all workshop shoppers see new IA.

**Rollback:** Restore `main-menu` from Phase 0 export. Collections remain (unlisted or listed) without menu pointers.

---

### Phase 10 — Cleanup & Getting started (optional, after stable)

| Step | Work |
| ---- | ---- |
| 10.1 | Getting started content pages + curated filter links |
| 10.2 | Retire obsolete smart collections / old menu orphans |
| 10.3 | Stop dual-write legacy tags (only if nothing else depends on them) |
| 10.4 | Update `docs/features/` when routes/nav are live |

**Impact:** Low–medium; mostly additive content.

---

### Suggested schedule (flexible)

| Week | Phases | Notes |
| ---- | ------ | ----- |
| 1 | 0 → 1 | Code + pilot tags |
| 2 | 2 → 3 | Pilot pages + sanding |
| 3 | 4 → 5 | Cutting + paints (largest QA) |
| 4 | 6 → 7 | Markers + tool-family pages + airbrush + misc |
| 5 | 8 → 9 | Hub + nav cutover (plan a quiet window) |
| later | 10 | Getting started content |

Phases 2–8 can be **one phase per deploy** or batched (e.g. 2+3 together) if pilot goes well.

---

## 9. Implementation checklist

- [x] Phase 1: classifier + dual-write tags (tapes, decals pilot)
- [x] Tag schema in code (`ts:*` constants under `App\Support\Products\Storefront`)
- [x] Phase 2: pilot collection filters (theme: Width on tapes, hide Price on tapes/decals; `?ovs_width=` URLs)
- [x] Phase 2: decal storefront filters **skipped** — options TBD by operator (see §3.8)
- [ ] Phase 2: Search & Discovery native tag filters (optional — skipped)
- [x] Phase 2: unlisted smart collections `tapes` + `decals` live (direct URL; not in menu)
- [x] Phase 3: sanding classifier + `ts:sand:*` tags + unlisted `/collections/sanding`
- [x] Phase 3: theme Form + Grit multiselect filters on sanding (`?ovs_form=` / `?ovs_grit=`)
- [x] Phase 4: cutting classifier + `ts:cut:*` tags + unlisted `/collections/nippers-and-knives`
- [x] Phase 4: theme Category + Style multiselect filters (`?ovs_cut_category=` / `?ovs_cut_style=`)
- [x] Phase 5: paints classifier + `ts:paint:*` tags + unlisted `/collections/paints`
- [x] Phase 5: theme Product + Application + Paint type multiselect filters
- [x] Phase 6: markers classifier + `ts:marker:*` tags + unlisted `/collections/markers`
- [x] Phase 6: theme Brand + Type + Tip multiselect filters (`?ovs_marker_brand=` / `?ovs_marker_type=` / `?ovs_marker_tip=`)
- [x] Phase 6: **MS-58** excluded from markers → **`workshop-misc`** in Phase 7 (§4.7)
- [x] Phase 7: tool-family classifiers + `workshop-misc` + `airbrush` + unlisted collections (§2, §3.2–3.7, §3.14)
- [x] Phase 7: theme filters on brushes / drills / tweezers / scribing-tools / airbrush; misc + adhesives availability-only
- [x] Phase 8: `tools-and-supplies` hub (department sidebar links + availability per §3.1; shelf breadcrumbs under title)
- [x] Phase 9 nav cutover + rollback snapshot tested (2026-06-20: `main-menu-20260620_115924.json`)
- [ ] Phase 10 Getting started (separate task — see task tracker `storefront_getting_started_nav`)
- [ ] `docs/features/` updated after Phase 9 nav is live
- [x] **Follow-up:** Category labels & filter option order review (§4.6) — Category label applied on hub; §2 nav order used for cutover

---

## 10. Revision history


| Date       | Change                                                                                                 |
| ---------- | ------------------------------------------------------------------------------------------------------ |
| 2026-06-04 | Initial nav/taxonomy plan (`shopify-nav-taxonomy-plan.md`)                                             |
| 2026-06-07 | Split pages: sanding, tapes, decals, nippers & knives; paint types; classification rules; navbar order |
| 2026-06-07 | Phased rollout plan (§8): additive tags → unlisted collections → nav cutover last |
| 2026-06-07 | Single source of truth: `ProductStorefrontClassifier` + call-site table (§8.1) |
| 2026-05-25 | §4.6 + task tracker: deferred category/filter order review before Phase 9 |
| 2026-05-25 | Phase 6 markers live; §4.7 MS-58 / misc workshop SKU placement notes |
| 2026-05-25 | **§2 nav revision:** split general Tools into Brushes / Drills / Tweezers / Scribing / Adhesives + **Other tools & supplies** (`workshop-misc`); clarity-over-brevity principle |
| 2026-05-25 | Phase 7 live: 7 tool-family + airbrush + workshop-misc collections (79 SKUs) |
| 2026-05-25 | Phase 8 live: `tools-and-supplies` hub smart collection (OR union of `ts:dept:*`) + Department filter (`?ovs_ts_dept=`) |
| 2026-05-25 | Phase 8 UX: hub department sidebar links + shelf breadcrumbs under title; removed hub Department checkbox filter |
| 2026-05-25 | Phase 9 partial: all tools & supplies collections + hub published via `shopify:storefront-nav-cutover --skip-menu`; nav cutover command + rollback export added |
| 2026-06-20 | Phase 9 complete: `main-menu` → **Tools & Supplies** dropdown (14 shelves); rollback `main-menu-20260620_115924.json` |


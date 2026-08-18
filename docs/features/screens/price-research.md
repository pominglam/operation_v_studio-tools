# Price research (“Pricing”, `/price-research`)

**Main page:** `resources/js/pages/PriceResearchPage.vue`  
**Related:** `PriceResearchReportsPage.vue`, `PriceResearchRunLogsPage.vue`

Layout note: **`App.vue`** removes max width constraint for **`/price-research`**, enabling a wide competitor grid.

---

## Main table UX

Loads **`GET /api/v1/price-research/products`** with pagination + filters persisted via **`clearPageState` / `loadPageState` / `savePageState`** helper (watchers serialize search, freshness multi-select, site checklist, **`disabledSiteKeys`**, sort keys, pagination).

### Columns & derived math

Shows per-product SKU, barcode text, descriptions, freshness indicator (**fresh vs expired TTL** mirrored from backend), **`available`**, **`landed_cost` / fallback `cost` Po band fields**, **`selling_price`**, **`multiplier`**, **`quotes[]`** matrix for each crawler result (availability tinting rules: **`sold_out` red accent**, **`not_found` grey**). A **PO lines** link immediately to the right of each selling-price input opens `ProductPoLinesDrawer` with that SKU’s PO history and unit/shipping/surcharge/landed breakdown.

Footer totals accumulate parsed money fields for **`landed_cost` vs `cost` preference** identical to grid row logic (**`parseMoney`** on page slice).

### Filters

| Control                                                       | Backend param(s)                                     |
| ------------------------------------------------------------- | ---------------------------------------------------- |
| Text search (`sku/description/barcode` semantics server-side) | `search`                                             |
| Fresh vs expired chips                                        | `freshness[]`                                        |
| Competitor sites multi-toggle                                 | **`quote_sites[]`** (when site not disabled locally) |
| Purchase order multi-select                                   | `purchase_order_uuids[]`                             |
| First-time vs already-seen products in selected PO(s)         | `po_product_novelty=new\|existing`                   |

### Disabled sites (operator preference)

Separate from multi-select chips: **`disabledSiteKeys`** array filtered out visually for run-time site selection persistence (cross-saved JSON state). Hydrated partly from **`GET /api/v1/price-research/filter-options`** payload key **`disabled_site_keys`** merging server recommendations with persisted local disables.

Whenever disabled set updates, **`normalizeRunSites`** ensures **`POST /price-research/run`** body excludes AliExpress scaffolding when needed (see implementation near watchers).

Site catalog enumerated in **`allSites` constant**: **AliExpress, Argama, Panda, Canada Computers, Canadian Gundam, Hobby Bee, HobbyWholesale, Meeplemart, Hobby Sense, Gundam Hangar** (plus comment about dynamic disable states).

AliExpress flagged separately—the run payload builder purposely treats AliExpress distinctly (credential / Playwright interplay).

---

## Running research

Buttons:

| Action                                             | Endpoint                                                          | Notes                                                                                      |
| -------------------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| **Run refresh** scoped to page selection / filters | **`POST /api/v1/price-research/run`** body **`{ ids?, force? }`** | Honors disabled sites + Ali constraints                                                    |
| **Force refresh row** (**per product**)            | Same endpoint with narrowed ids + **`force: true`**               | Guarded **`isRecrawlBlocked`** when global run spinner active unrelated to table hydration |

Immediately after dispatch:

- Hydrates **`activeRunId`** and begins **`pollRun`** loop via **`fetch /api/v1/price-research/runs/{id}`** until terminal state (**completed/failed**) then reloads listing.
- On mount also queries **`GET /api/v1/price-research/runs/latest`** to attach progress UI if queued/running.

### Operational guard rails

Blocking states:

| Condition               | Meaning                                                        |
| ----------------------- | -------------------------------------------------------------- |
| `loading` disables run  | Prevents simultaneous confusing requests while table hydrating |
| `running`/`isRunActive` | Locks certain buttons to avoid stacking runs                   |

Polling implementation uses guarded loop (comment asserts prevention of overlapping timers historically bugfixed).

---

## Quote maintenance UX

Row-level actions hitting:

| Interaction                          | Endpoint                                                                                                                  |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| Hard delete erroneous quote artifact | **`DELETE /api/v1/price-research/products/{uuid}/quotes/{siteKey}`** — confirm dialog keyed by **`deleting` ref** pairing |
| “Report suspicious quote” workflow   | **`POST /api/v1/price-research/reports`** JSON containing product + quote metadata snapshot + **`note`** string           |

Handled flag surfaces success/failure ephemeral messaging refs.

---

## Navigation affordances embedded in Pricing page header

| Link                                               | Destination                           |
| -------------------------------------------------- | ------------------------------------- |
| **Reported quotes**                                | `/price-research/reports`             |
| **Latest run crawl logs** (when `runStatus` known) | `/price-research/runs/{runUuid}/logs` |

---

## Reported quotes page (`/price-research/reports`)

**File:** `PriceResearchReportsPage.vue`

| Feature                             | API                                                            |
| ----------------------------------- | -------------------------------------------------------------- |
| Paginates historical operator notes | **`GET /api/v1/price-research/reports`**                       |
| Marks card handled after review     | **`PATCH /api/v1/price-research/reports/{numericId}/handled`** |

Template includes breadcrumb **`Back to research`**.

---

## Run logs drill-down (`/price-research/runs/:id/logs`)

**File:** `PriceResearchRunLogsPage.vue`

- Polls **`GET /api/v1/price-research/runs/{uuid}`** (run meta) concurrently with **`GET .../runs/{uuid}/logs`** paginated textual entries (per competitor attempt per SKU).
- Colored **`status`** pill mapping for quick scan (**found/not_found/error/running** palette).
- Use when diagnosing stuck runs referenced from main page link.

📘 Crawler internals: **`docs/requirements/price-research.md`** & **`price-research-crawlers.md`**.

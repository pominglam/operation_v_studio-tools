# Product taxonomy and verification

## Purpose

Replace overloaded free-text product classification with an additive, evidence-backed taxonomy that works for every product, not only model kits. Existing fields remain available during rollout, are hidden from new admin workflows, and are removed only after every consumer has migrated and the future storefront mega-menu is live.

## Scope

- All ERP products, including active, archived, unpublished, and non-model-kit records.
- Product create/update/import, filters, sorting, exports, reports, maintenance tools, and Shopify push payloads.
- A review workflow that lets operators verify, approve, override, and audit researched values quickly.
- Additive Shopify metafields. Existing tags, collections, navigation, and the live header must continue working.
- A later cleanup pass after mega-menu launch removes legacy fields, compatibility code, stale Shopify taxonomy, and temporary navigation logic.

## Canonical product fields

All taxonomy fields are nullable unless noted.

| Field | Meaning | Examples |
| --- | --- | --- |
| `department` | Broad store department; replaces `main_type` | `model kits`, `tools`, `supplies`, `figures`, `accessories`, `misc` |
| `manufacturer` | Company that made the product | `Bandai Spirits`, `Kotobukiya`, `Good Smile Company` |
| `franchise` | Customer-recognized IP or world | `Gundam`, `Pokémon`, `Evangelion` |
| `product_line` | Official commercial product line | `Gunpla`, `Pokémon Plamo Collection`, `30 Minutes Missions`, `MODEROID` |
| `subline` | Official subdivision within a product line | `HGUC`, `HGIBO`, `Ver.Ka`, `MGEX`, `MGSD`, `Quick!!` |
| `grade` | Official build/grade designation when applicable | `HG`, `RG`, `MG`, `MGEX`, `MGSD`; null when no official grade exists |
| `series` | Source title, continuity, or media series | `Gundam SEED`, `The Witch from Mercury`, `Armored Core VI` |
| `scale` | Physical scale designation | `1/144`, `1/100`, `non-scale` |
| `workshop_shelf` | Tools & Supplies nav shelf (ERP authority; maps to storefront `ts:dept:*` at mega-menu cutover) | `Sanding`, `Nippers & knives`, `Markers` |
| `accessory_kind` | Model kit accessory sub-type when `department` is `accessories` | `display_stand`, `option_parts`, `detail_parts`, `scene_base` |

### Department notes

- **`figures`** — pre-assembled, non-kit collectibles (e.g. CCS Toys). No grade/subline; optional franchise and scale.
- **`misc` + `product_line: Keychains`** — rubber mascot / keychain merchandise under storefront **Miscellaneous** (not model kits).
- **`accessories`** — buildable model kit add-ons (action bases, option parts, detail parts). Distinct from figures and T&S supplies.

`vendor` remains the supplier and is not taxonomy.

### Semantics

- Grade is optional and must never be populated with a product-line abbreviation merely to avoid null.
- Series is universal, not Gundam-only.
- Precise sub-lines are stored even when the storefront groups low-volume values under a presentation-only label such as “Other HG lines.”
- Pokémon is presented as one customer-facing product line. Official sub-lines may be stored but remain hidden from navigation and filters until explicitly enabled.
- Scale and product format are distinct, but no new format field is introduced in this rollout. Product line plus department covers current requirements.
- Gundam Artifact is a miniature model-kit product line, not No Grade Gunpla.

### Gunpla Master Grade family (Bandai)

| Line | `type` (legacy) | `grade` | `subline` | Title signals |
| --- | --- | --- | --- | --- |
| Standard MG | `MG` | `MG` | null | `Master Grade`, `MG 1/100`, `MG MS-…` |
| MG Ver.Ka | `MG` | `MG` | `Ver.Ka` | `Ver.Ka` / `Ver. Ka` (not `Ver.2.0`, `Ver RM`, `Ver.3.0`) |
| MGEX | `MGEX` | `MGEX` | `MGEX` | `MGEX` |
| MGSD | `MGSD` | `MGSD` | `MGSD` | `MGSD` |

Resolver: `App\Support\Products\ProductGunplaMgClassificationResolver`. Bulk correction: `php artisan products:mg-classify --apply [--push-all-mg]`.

**Shopify absolute tags (ERP → push):** prefix `mk:` from `ModelKitStorefrontTagResolver` — e.g. `mk:dept:model-kits`, `mk:grade:mg`, `mk:subline:ver_ka`, `mk:line:mg-standard`. Storefront nav uses **smart collections** on these tags (`/collections/mg-ver-ka`, etc.) — not search URLs or fuzzy title search. **Full shelf workflow:** [model-kit-storefront-shelves.md](./model-kit-storefront-shelves.md). Commands: `php artisan products:model-kit-shelf-collections`, `php artisan products:push-model-kit-tags`.


| Legacy field | During rollout | Intended canonical replacement |
| --- | --- | --- |
| `main_type` | Continue reading/writing through a compatibility mapper | `department` |
| `type` | Continue reading/writing through a compatibility mapper | `product_line` and/or `subline` |
| `brand` | Preserve during migration; do not expose in the new editor | `franchise` |
| `grade`, `series`, `scale` | Retain columns and normalize values | Same canonical semantics |

New APIs expose canonical fields and legacy aliases during the transition. New UI controls use canonical fields only. Removal of legacy columns is a separate approved cleanup task.

## Research and provenance

### Source priority

1. Manufacturer or official product page/catalog.
2. Official regional distributor.
3. Trusted specialist databases or established model-kit retailers.
4. Existing ERP title/source metadata when no better source exists.

No field may be silently guessed. Best-effort proposals are allowed, but must record confidence and evidence and remain visible in the operator confirmation list.

### Verification records

Store one verification record per product proposal/version with:

- Product ID.
- Status: `proposed`, `verified`, or `overridden`.
- Proposed canonical values as JSON.
- Previous canonical values as JSON.
- Per-field evidence containing value, source URL, source label, confidence, and notes.
- Overall confidence.
- Research method/provider.
- Researched, verified, and overridden timestamps and actor information where available.
- Operator notes.

Verification records are immutable evidence snapshots. Applying or overriding a proposal updates the product in a service transaction and records the resulting state.

## Research workflow

- Research runs as a queued, resumable batch with persisted progress and counts.
- Existing stored PDP/source URLs are preferred before new web lookups.
- The run is idempotent for a product and research version.
- External calls use provider interfaces, timeouts, retries with jitter, and masked external API logging.
- Partial failures do not discard successful product proposals.
- Uncertain values are populated as proposals, clearly flagged, and included in the confirmation report.
- Applying proposals is a separate operator action; research does not directly overwrite approved taxonomy.

## Admin workflow

Add a Taxonomy review area to the Products screen:

- Summary counts: all, proposed, low confidence, verified, overridden, and research failures.
- Sortable/filterable table with SKU, title, archived/published state, current values, proposed values, confidence, and evidence links.
- Filters for every canonical field, verification status, confidence, archived state, and differences-only.
- Expandable per-field evidence.
- Approve, edit-and-approve, and bulk approve actions.
- Confirmation before bulk apply.
- Export of the confirmation list.
- Quick link from a product row to its taxonomy evidence.

Canonical taxonomy fields are available in product add/edit/bulk-update controls, list filters, sorting, filter options, and API resources. Legacy controls are hidden.

## Reports and exports

- Inventory-by-department uses `department`.
- Product catalog reporting can group/filter by manufacturer, franchise, product line, sub-line, grade, series, and scale.
- CSV exports include canonical fields while retaining required legacy columns during compatibility.
- Reports must not combine manufacturer, franchise, product line, or grade semantics.

## Data migration rules

- Migrate all products, including archived records.
- Normalize duplicates, casing, punctuation, mojibake, and aliases through controlled mappings.
- Obvious non-kits currently under model kits move temporarily to `misc`.
- Generate a follow-up reclassification report for temporary `misc` records after model-kit taxonomy is complete.
- Preserve explicit operator overrides on later research runs.
- Dry-run and apply modes must report matched, changed, unchanged, uncertain, and failed counts.
- Migration must be rerunnable and safe after partial completion.

## Shopify rollout safety

- ERP is the source of truth.
- Define additive product metafields for canonical taxonomy only after ERP proposals are applied and verified.
- Do not remove or rewrite legacy tags/collections during this rollout.
- Do not change the current live navbar, menus, collection handles, or live theme header.
- Do not publish or enable the new mega-menu.
- Shopify writes use the existing guarded backend GraphQL write infrastructure and explicit preview/apply flow.
- After the future mega-menu launch, run the separately tracked cleanup of legacy tags/metafields and temporary navigation compatibility.

## API contract

Versioned API endpoints provide:

- Taxonomy proposal list and summary.
- Product evidence detail.
- Research dispatch and status.
- Single approve/override.
- Bulk approve.
- Confirmation CSV export.
- Canonical filter options.

Validation uses controlled lengths and nullable semantics. Bulk actions return stable count/error shapes.

## Testing and QA

- Migration tests cover reversible schema, relationships, indexes, and all-record scope.
- Unit tests cover aliases, normalization, nullable grade behavior, and field-level confidence.
- Feature tests cover list/filter/sort, evidence detail, approve/override, bulk confirmation, export, auth/validation failure, idempotency, and archived products.
- External research tests use fake providers and cover success, upstream failure, partial success, and reruns.
- Vitest covers taxonomy filters, evidence display, edits, confirmations, and persisted page state.
- Playwright covers the full review flow, a filter combination, approve/override, bulk confirmation, and cleanup.
- Browser QA uses real local data and verifies counts and persisted product values.
- Shopify tests verify additive metafields while proving current tags/collections/navigation inputs are unchanged.

## Rule compliance

- `architecture.mdc`, `controllers.mdc`, `services.mdc`, `dal.mdc`: thin controllers, service transactions, repository persistence, provider interfaces.
- `frontend.mdc`, `design_system_rules.mdc`: typed Vue components, reusable taxonomy review components, sortable columns, accessible help.
- `testing.mdc`, `always-test-before-done.mdc`, `rigorous-qa-before-handoff.mdc`, `verify-ui-before-done.mdc`: TDD, Vitest, Playwright, real-browser validation.
- `typesafety.mdc`, `formatting.mdc`: strict types, PHPStan, Pint, Prettier.
- `data-pull-features.mdc`: queued research, sync logs, idempotency, status polling, partial failure handling.
- `shopify-erp-integration.mdc`, `shopify-product-set-push.mdc`, `product-updates.mdc`: ERP first, backend GraphQL only, additive guarded writes, no live theme cutover.
- `features-documentation-catalog.mdc`: update Products, Maintenance/report, backend HTTP, and navigation catalogs.
- `confirm-delete-actions.mdc`: bulk apply requires confirmation; no destructive legacy cleanup occurs in this rollout.

## Deferred cleanup

Track but do not execute until the new mega-menu is explicitly approved and live:

- Remove legacy product taxonomy fields and API aliases.
- Remove compatibility mappers and hidden legacy UI.
- Reclassify temporary `misc` records into final departments.
- Remove replaced Shopify tags/metafields and obsolete collection/filter wiring.
- Remove temporary mega-menu/theme-ID guards and migrate navigation to its final source.

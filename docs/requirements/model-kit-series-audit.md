# Model kit series audit and fix workflow

Automated, rerunnable check that every Gunpla (and other series-shelf) product has the **correct ERP `series`** value and matching **`mk:series:*`** Shopify tag.

Related: [model-kit-taxonomy-audit.md](./model-kit-taxonomy-audit.md), [model-kit-storefront-shelves.md](./model-kit-storefront-shelves.md), [product-taxonomy-and-verification.md](./product-taxonomy-and-verification.md).

---

## When to run

- Operator reports mis-classified series on collection filters or mega-menu landings.
- After adding inference rules to `ModelKitSeriesCatalog`.
- Before series-heavy launches (UC/AU facet columns, new shelf).
- Periodically — same hygiene cadence as full taxonomy audit.

---

## Quick commands

```powershell
# 1. Full series audit (title rules + gunpla.fandom.com kit lookup)
docker exec pricing-tool-php php artisan products:model-kit-series-audit

# Skip Fandom (rules-only, faster)
docker exec pricing-tool-php php artisan products:model-kit-series-audit --no-fandom

# 2. One series shelf at a time (double-check UC/AU buckets)
docker exec pricing-tool-php php artisan products:model-kit-series-audit --series=gundam_unicorn
docker exec pricing-tool-php php artisan products:model-kit-series-audit --series=iron_blooded_orphans

# 3. Only high-confidence auto proposals in the apply template
docker exec pricing-tool-php php artisan products:model-kit-series-audit --min-confidence=high

# Reports:
#   storage/app/model-kit-series-proposals.md   ← START HERE (product names + current → proposed)
#   storage/app/model-kit-series-proposals.csv  ← same list, spreadsheet-friendly
#   storage/app/model-kit-series-audit.md
#   storage/app/model-kit-series-audit.json
#   storage/app/model-kit-series-approved.template.json  ← copy & edit

# 4. Operator approves → save as model-kit-series-approved.json

# 5. Apply ERP + Shopify push
docker exec pricing-tool-php php artisan products:model-kit-series-apply --dry-run
docker exec pricing-tool-php php artisan products:model-kit-series-apply

# 6. Re-audit — proposed_change / proposed_fill counts should drop
docker exec pricing-tool-php php artisan products:model-kit-series-audit
```

Thin script wrapper (same as artisan): `php scripts/model-kit-series-audit.php`

---

## What it checks

**Scope:** Model-kit push scope (same as `products:push-model-kit-tags`), minus accessories / 30MM / Pokémon lines that do not use series facets.

For each product:

| Layer | Source | Compare |
| --- | --- | --- |
| **Stored ERP** | `products.series` | Operator truth |
| **Gunpla wiki** | `GundamFandomSeriesLookupService` → [gunpla.fandom.com](https://gunpla.fandom.com) kit pages (`\|franchise=`) | Kit-specific series (not MS lore pages) |
| **Inference** | `ModelKitSeriesInferenceService` + `ModelKitSeriesCatalog` rules | Title regex + HG subline hints |
| **Storefront tag** | `ModelKitStorefrontTagResolver` → `mk:series:*` | Slug from stored series |

Finding buckets:

| Bucket | Meaning |
| --- | --- |
| **proposed_change** | Stored series disagrees with high/medium inference |
| **proposed_fill** | Empty series, inference found a match |
| **review_low_confidence** | Subline-only hint (e.g. HGUC → 0079) — needs operator |
| **unresolved** | Gundam/Gunpla, no rule matched |
| **unknown_stored_series** | ERP series slug not in canonical catalog |
| **tag_mismatch** | Legacy tag slug drift |

The markdown report includes a **By proposed series shelf** section so you can review one series column at a time.

---

## Approved apply file format

`storage/app/model-kit-series-approved.json`:

```json
[
  {
    "sku": "5066733",
    "series": "Gundam Unicorn",
    "note": "operator approved — title says Unicorn"
  }
]
```

Apply command updates ERP first (`product-updates.mdc`), then pushes affected SKUs to Shopify.

---

## Extending inference rules

1. Add tag slug ↔ ERP display name in `App\Support\Products\ModelKitSeriesCatalog::erpSeriesByTagSlug()`.
2. Add ordered pattern rules in `ModelKitSeriesCatalog::inferenceRules()` (specific patterns **before** broad ones, e.g. SEED DESTINY before SEED).
3. Optional HG subline hint in `sublineSeriesHints()` (usually low/medium confidence).
4. Keep `ModelKitShelfCatalog` series tags aligned.
5. Re-run audit + spot-check one collection landing in browser.

Unit tests: `tests/Unit/Services/Products/ModelKitSeriesInferenceServiceTest.php`

---

## Series-by-series operator checklist

Use `--series={tag_slug}` from `ModelKitSeriesCatalog` / shelf `mk:series:*` suffix:

1. Run audit for that slug.
2. Open matching collection on AI Dev theme (`preview_theme_id=196218716241`).
3. Compare grid count to ERP rows with that series after apply.
4. Mark bucket empty before moving to next series.

Common slugs: `mobile_suit_gundam`, `zeta_gundam`, `gundam_unicorn`, `gundam_seed`, `gundam_seed_destiny`, `iron_blooded_orphans`, `the_witch_from_mercury`, `gundam_build_fighters`.

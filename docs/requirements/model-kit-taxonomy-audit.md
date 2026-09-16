# Model kit taxonomy audit and fix workflow

Operator-facing process for checking **every model-kit storefront product** against ERP taxonomy, derivation rules, and `mk:*` Shopify tags — without manual filter clicking.

Related: [product-taxonomy-and-verification.md](./product-taxonomy-and-verification.md), [model-kit-storefront-shelves.md](./model-kit-storefront-shelves.md), [tools-supplies-classification.md](./tools-supplies-classification.md).

---

## When to run

- After bulk imports, taxonomy rule changes, or “filter shows empty but shelf has products” reports.
- Before mega-menu / facet launches that depend on grade, line, or series tags.
- Periodically (e.g. monthly) as a hygiene check.

---

## Quick commands

```powershell
# 1. Audit (writes approval tables)
docker exec pricing-tool-php php artisan products:model-kit-taxonomy-audit

# Reports:
#   storage/app/model-kit-taxonomy-audit.md   — human approval table
#   storage/app/model-kit-taxonomy-audit.json — machine-readable

# 2. Operator approves rows in chat or a ticket

# 3. Apply ERP changes (example script — edit SKUs/patches per approval)
docker exec pricing-tool-php php scripts/apply-taxonomy-approved.php --dry-run
docker exec pricing-tool-php php scripts/apply-taxonomy-approved.php

# 4. Push tags to Shopify
docker exec pricing-tool-php php artisan products:push-model-kit-tags

# 5. Re-audit — counts should drop for fixed rows
docker exec pricing-tool-php php artisan products:model-kit-taxonomy-audit

# Series-only deep dive (mis-classified mk:series shelves)
docker exec pricing-tool-php php artisan products:model-kit-series-audit
# See docs/requirements/model-kit-series-audit.md
```

For **Tools & Supplies** rows (markers, paints, decals): use `products:storefront-classify` / `products:storefront-push-department` — not `push-model-kit-tags`.

---

## What the audit scans

**Scope** matches `products:push-model-kit-tags` (model kits + Gunpla accessories + option parts SKUs). Active (non-archived) rows only unless `--include-archived`.

For each product the audit compares:

| Layer | Source | Purpose |
| --- | --- | --- |
| **Stored ERP** | `products` canonical columns | Operator truth |
| **Derivation** | `ProductTaxonomyDerivationService` + evidence enrichment | Rule-based proposal |
| **Storefront tags** | `ModelKitStorefrontTagResolver` | What `mk:*` tags *should* be on Shopify |
| **Filter fit** | Grade slug vs hub keys (`re_100` ↔ ERP `RE` → tag `mk:grade:re`) | Empty facet diagnosis |

Finding categories:

- **erp_vs_derivation** — proposed fill or change (cosmetic manufacturer casing ignored).
- **questions** — derivation likely wrong (e.g. Mazinger → Gundam); **default keep ERP**.
- **missing_grade** — Gunpla-line products missing `mk:grade:*` (30MM/MODEROID/Pokémon excluded — see below).
- **accessory_mismatch** — `accessory_kind` vs line tags (option parts, action bases).
- **filter_grade_mismatch** — ERP grade vs hub filter param.

---

## Business rules (canonical)

### Grade field

- **`grade`** = Bandai (or manufacturer) **official grade name** when one exists: `HG`, `MG`, `RE`, `FM`, `EG`, etc.
- **Do not** put product-line abbreviations in `grade` just to avoid null.
- **Pokémon Plamo Collection** — **not Entry Grade**. Bandai markets it as **Pokémon PLAMO** with sub-lines (Quick!!, standard kits, Select Series). ERP keeps `grade` **null**; storefront uses synthetic shelf tag **`mk:grade:pokemon`** (resolver emits this from `product_line` / `franchise`).
- **30 Minutes / MODEROID / Kotobukiya** — no Gunpla grade; filtered by **line** or **franchise** facets, not HG/MG/RE.
- **RE/100** — ERP `grade = RE` → Shopify `mk:grade:re` → hub filter `ovs_mk_grade=re_100` (JS maps `re` ↔ `re_100`).
- **Full Mechanics** — ERP `grade = FM` → `mk:grade:fm`.
- **HGUC Messer** (`5059546`, title `HG 1/144 MESSER`) is **Gundam Hathaway** / franchise **Gundam** — not Sakura Wars. Title inference includes `\bMESSER\b`.

### Department boundaries

| Kind | `department` | Storefront |
| --- | --- | --- |
| Gunpla kits | `model kits` | `mk:*` tags, model-kits hub |
| Action bases, Gunpla option parts | `accessories` | `mk:line:action_base`, `mk:line:gunpla_option_parts` |
| 30MM option parts sets | `accessories` (operator preference) | `mk:line:30mm_accessories` |
| Stedi/Dspiae markers (`DMM-*`, `MK-*`, …) | `supplies` | `ts:*` tags, T&S Markers shelf — **not model kits** |
| LED units | `accessories` | not primary kits |

### Manufacturer

- Canonical casing: **`Bandai Spirits`** (not `BANDAI HOBBY`).
- Cosmetic-only changes do not affect Shopify tags.

### Hub filter empty grid (common bug)

If products exist on paginated collection HTML but a hub facet shows **0 rows**, check:

1. **Tags** — product has correct `mk:*` on Shopify (`products:push-model-kit-tags`).
2. **Theme JS** — hub must load full slim index before filtering (`ensureIndexLoaded` in `ovs-model-kit-collection-filters.js`).
3. **Wrong collection** — dedicated shelves (`/collections/action-base`) vs hub query params (`?ovs_mk_grade=re_100`).

---

## Apply approved changes

1. **Backup** (prod-like DB): `php artisan db:backup --yes --description="Pre-taxonomy-apply" --created-by=system`
2. Edit `scripts/apply-taxonomy-approved.php` patches or apply via Products UI / one-off SQL.
3. Push Shopify: model kits → `products:push-model-kit-tags`; T&S → storefront push commands.
4. Re-run audit and spot-check filters in browser.

---

## AI agent checklist

When an operator reports “filter empty” or “classify everything”:

1. Run `products:model-kit-taxonomy-audit` — do **not** ask operator to click every facet first.
2. Separate **data** issues (wrong ERP/tags) from **theme** issues (JS index race).
3. Present findings as **approval tables** — do not bulk-apply `erp_vs_derivation` without operator OK (derivation has false positives).
4. Default **questions** rows to **keep ERP**.
5. After apply: push tags, re-audit, browser-verify the reported URL.

Implementation: `App\Services\Products\ModelKitTaxonomyAuditService`, `App\Support\Products\Storefront\ModelKitStorefrontTagResolver`, `App\Services\Products\ProductTaxonomyDerivationService`.

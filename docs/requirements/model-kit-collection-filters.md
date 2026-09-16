# Model kit collection filters

Client-side OVS filters on **85** ERP-provisioned model-kit smart collections (`ModelKitShelfCatalog`). **Availability** is always the **last** filter — native Shopify `filter.v.availability` only; OVS hides native price/tag facets on these shelves.

Regenerate manifests after catalog changes:

- **Maintenance UI:** `/maintenance` → **Regenerate model-kit filter manifest** (typically under 1 second)
- **CLI:** `php artisan storefront:model-kit-collection-filter-manifest-generate`

## Global rules

| Rule | Detail |
| --- | --- |
| Filter order | Custom OVS groups (profile-specific) → **Price** (when present) → **Availability** (Shopify native, always last) |
| Pagination | 250 products/page on filtered shelves; hidden while any OVS param is active |
| Matching | Union (OR) of checked values **within** a group; intersection (AND) **across** groups (Grade ∩ Gundam series, etc.). Price and Availability also AND. |
| URL params | Comma-separated multiselect: `ovs_mk_grade`, `ovs_mk_series`, `ovs_mk_franchise`, `ovs_mk_line`, `ovs_mk_price` |
| Product attrs | `data-ovs-mk-grade`, `data-ovs-mk-sublines`, `data-ovs-mk-series`, `data-ovs-mk-lines`, `data-ovs-price-cents` on grid items |
| Filtered-card Sold out | Client-filtered hub cards (mega menu / `ovs_mk_*`) show Dawn **Sold out** **on the photo** (`position:absolute` overlay) when the kit index `a` flag is unavailable **and** the kit is not an open store preorder (`op`). Index `pc` is **Preorder closed** only when the ERP offer is closed or past `window_ends_on` — zero ERP stock on an **open** preorder is not closed. Preorder closed wins over Sold out. |
| Listing CTAs | See **[Listing CTAs](#listing-ctas)** below. |
| Theme path | `OVS_SHOPIFY_THEME_PATH` in `.env`, or sibling `../ovs-shopify-theme` |

## Profile → filter groups

| Profile | Custom filter groups (before Availability) |
| --- | --- |
| `gunpla-hub` | Grade → Gundam series → 30 minutes label → Other series → Other model kits → Price |
| `uc-hub` | Grade → Gundam series (UC) → Price |
| `au-hub` | Grade → Gundam series (AU) → Price |
| `uc-other-hub` | Grade → UC series → Price |
| `au-other-hub` | Grade → AU series → Price |
| `grade-simple` | Grade → Gundam series → Price |
| `subline-shelf` | Grade → Gundam series → Price |
| `series-leaf` | Grade → Price |
| `franchise-leaf` | Grade → Price |
| `franchise-rollup` | Other series → Grade → Price |
| `30mm-hub` | 30 minutes label → Price |
| `30mm-leaf` | 30 minutes label → Price |
| `brand-leaf` | Grade → Price |
| `accessories` | Price |

## Per-collection matrix

| Handle | Title | Profile | Filter groups |
| --- | --- | --- | --- |
| `model-kits` | Model kits | `gunpla-hub` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), 30 minutes label (`ovs_mk_line`), Other series (`ovs_mk_franchise`), Other model kits (`ovs_mk_franchise`), Price (`ovs_mk_price`) |
| `entry-grade-eg` | Entry Grade | `grade-simple` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `high-grade-hg` | High Grade (HG) | `grade-simple` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `real-grade-rg` | Real Grade (RG) | `grade-simple` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `perfect-grade-pg` | Perfect Grade (PG) | `grade-simple` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `master-grade-mg` | Master Grade (MG) | `grade-simple` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `mg-standard` | MG | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `mg-ver-ka` | MG Ver.Ka | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `mgex` | MGEX | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `master-grade-sd-mgsd` | Master Grade SD (MGSD) | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-gundam` | SD Gundam | `grade-simple` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-ex-standard` | SD EX-Standard | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-cross-silhouette` | SD Cross Silhouette | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-world-heroes` | SD World Heroes | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-bb-senshi` | SD BB Senshi | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-g-generation` | SD G Generation | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-build-fighters` | SD Build Fighters | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `sd-gunpla-kun` | Gunpla-kun | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `gunpla-option-parts` | Gunpla Option Parts | `accessories` | Price (`ovs_mk_price`) |
| `action-base` | Action Bases | `accessories` | Price (`ovs_mk_price`) |
| `hg-universal-century` | HG Universal Century | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `hg-gundam-seed` | HG Gundam SEED | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `hg-after-colony` | HG After Colony | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `hg-iron-blooded-orphans` | HG Iron-Blooded Orphans | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `hg-build-fighters` | HG Build Fighters | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `hg-build-divers` | HG Build Divers | `subline-shelf` | Grade (`ovs_mk_grade`), Gundam series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `gundam-universal-century` | Gundam Universal Century | `uc-hub` | Grade (`ovs_mk_grade`), Gundam series (UC) (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `gundam-alternate-universes` | Gundam Alternate Universes | `au-hub` | Grade (`ovs_mk_grade`), Gundam series (AU) (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `gundam-other-uc-series` | Other UC series | `uc-other-hub` | Grade (`ovs_mk_grade`), UC series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `gundam-other-au-series` | Other AU series | `au-other-hub` | Grade (`ovs_mk_grade`), AU series (`ovs_mk_series`), Price (`ovs_mk_price`) |
| `gundam-mobile-suit-gundam` | Mobile Suit Gundam | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-zeta` | Zeta Gundam | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-zz` | Gundam ZZ | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-chars-counterattack` | Char's Counterattack | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-0080` | Gundam 0080: War in the Pocket | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-f91` | Gundam F91 | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-0083` | Gundam 0083: Stardust Memory | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-08th-ms-team` | The 08th MS Team | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-the-origin` | Gundam: The Origin | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-thunderbolt` | Gundam Thunderbolt | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-narrative` | Gundam Narrative | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-sentinel` | Gundam Sentinel | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-unicorn` | Gundam Unicorn | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-seed` | Gundam SEED | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-wing` | Gundam Wing | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-00` | Gundam 00 | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `g-gundam` | G Gundam | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-build-fighters` | Gundam Build Fighters | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-build-divers` | Gundam Build Divers | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-age` | Gundam Age | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-hathaway` | Gundam Hathaway's Flash | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-iron-blooded-orphans` | Gundam Iron-Blooded Orphans | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-witch-from-mercury` | Gundam The Witch from Mercury | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-reconguista-in-g` | Gundam Reconguista in G | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-x` | After War Gundam X | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `gundam-requiem-for-vengeance` | Gundam: Requiem for Vengeance | `series-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `patlabor` | Patlabor | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `macross-delta` | Macross Delta | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `armored-trooper-votoms` | Armored Trooper Votoms | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `mazinger` | Mazinger | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `getter-robo` | Getter Robo | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `kotetsu-jeeg` | Kotetsu Jeeg | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `super-robot-wars` | Super Robot Wars | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `armored-core` | Armored Core | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `doraemon` | Doraemon | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `sakura-wars` | Sakura Wars | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `linebarrels-of-iron` | Linebarrels of Iron | `franchise-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `other-series` | Other series | `franchise-rollup` | Other series (`ovs_mk_franchise`), Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `30-minutes-missions` | 30 Minutes Missions | `30mm-hub` | 30 minutes label (`ovs_mk_line`), Price (`ovs_mk_price`) |
| `30-minutes-armored-core` | 30 Minutes Missions Armored Core | `30mm-leaf` | 30 minutes label (`ovs_mk_line`), Price (`ovs_mk_price`) |
| `30-minutes-sisters` | 30 Minutes Sisters | `30mm-leaf` | 30 minutes label (`ovs_mk_line`), Price (`ovs_mk_price`) |
| `30-minutes-fantasy` | 30 Minutes Fantasy | `30mm-leaf` | 30 minutes label (`ovs_mk_line`), Price (`ovs_mk_price`) |
| `30-minutes-preference` | 30 Minutes Preference | `30mm-leaf` | 30 minutes label (`ovs_mk_line`), Price (`ovs_mk_price`) |
| `30-minutes-accessories` | 30 Minutes Accessories | `30mm-leaf` | 30 minutes label (`ovs_mk_line`), Price (`ovs_mk_price`) |
| `pokemon` | Pokémon | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `kotobukiya` | Kotobukiya | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `moderoid` | MODEROID | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `keroro` | Keroro | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `snaa` | SNAA | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `one-piece` | One Piece | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `eureka-seven` | Eureka Seven | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `mechatrowego` | MechatroWeGo | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `plamax` | PLAMAX | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |
| `evangelion` | Evangelion | `brand-leaf` | Grade (`ovs_mk_grade`), Price (`ovs_mk_price`) |

## Out of scope (not in `ModelKitShelfCatalog`)

- `beginner-kits` — price-rule collection, not `mk:*` shelf
- `latest-arrivals` — store-wide arrivals, not taxonomy shelf

## Listing CTAs

Every **product listing page** card shows a Dawn full-width quick-add under the price. Labels:

| State | Button | Also on photo |
| --- | --- | --- |
| In stock | **Add to cart** | — |
| Unavailable, not an open store preorder | **Sold out** (disabled) | **Sold out** badge |
| Open store preorder | **Pre-order** | — |
| Closed store preorder | **Preorder closed** (disabled) | **Preorder closed** badge |

**Required on**

- Client-filtered MK hubs (`/collections/model-kits?ovs_mk_*` and other MK filter handles) — synthesized JS cards
- Native collection grids (MK shelves, T&S, Miscellaneous, Pre-orders)
- Search results
- PDP related-products row
- Product pages (PDP buy button)

**Exception — homepage**

- `/` Featured Products and Latest Arrivals: **no** Add to cart / Sold out / Pre-order / Preorder closed button. Photo + title + price only. Photo sold-out badges may still appear.
- Theme: `templates/index.json` featured-collection `quick_add` = `none`. Do not re-enable without operator approval.

E2e: `listingCtaCases` on every collection listing asserts a CTA on each visible card; homepage is `expectNoCtas`. Sold-out photo-badge geometry stays on filtered hubs.

# Model kit storefront shelves (`mk:*` tags → smart collections)

**Status:** Mega menu fully on `/collections/…` URLs (2026-08-26). **Beginner kits** remains a price-rule collection (not `mk:*`).  
**Related:** [product-taxonomy-and-verification.md](./product-taxonomy-and-verification.md) · [storefront-content-hierarchy.md](./storefront-content-hierarchy.md)

---

## Principle (customer-facing)

Visitors must never land on a search results page with internal tag syntax in the search box.

| Layer | Role |
| --- | --- |
| **ERP** | Canonical taxonomy (`grade`, `subline`, `series`, `franchise`, `product_line`, …) |
| **Push** | Absolute Shopify tags prefixed `mk:` from `ModelKitStorefrontTagResolver` |
| **Shopify Admin** | **Smart collections** — product tag **equals** each `mk:*` rule (OR when noted) |
| **Theme / nav** | Mega menu → **`/collections/{handle}`** only |

Mirrors Tools & Supplies (`ts:dept:*` → `/collections/tapes`, etc.).

**Never use in nav:** fuzzy title search, unquoted `tag:mk:…`, or quoted `tag:'mk:…'` (debug only).

---

## Tag vocabulary (`mk:*`)

| Tag | Source |
| --- | --- |
| `mk:dept:model-kits` | Every model kit |
| `mk:grade:{slug}` | ERP `grade`, else `type` |
| `mk:subline:{slug}` | ERP `subline`; else `type` when it differs from grade; SD heuristics from description |
| `mk:line:mg-standard` | Grade MG, no subline |
| `mk:line:gunpla` | Franchise Gundam, product line Gunpla, or gunpla grade family |
| `mk:line:moderoid` | Description contains MODEROID |
| `mk:line:30mm_armored_core` | 30MM + Armored Core series/type |
| `mk:line:30mm_accessories` | 30 Minutes Missions/Sisters accessory SKUs (Extended Armament Vehicle, CUSTOMIZE WEAPONS/EFFECT, OPTION in description) |
| `mk:series:{slug}` | ERP `series` |

**Code:** `ModelKitStorefrontTagResolver`, `StorefrontTag`, shelf registry `ModelKitShelfCatalog`.

---

## Shelf registry (45 collections)

Single source of truth: `App\Support\Products\Storefront\ModelKitShelfCatalog`  
Provision: `php artisan products:model-kit-shelf-collections`

| Mega menu area | Handle | Title | Rule (summary) |
| --- | --- | --- | --- |
| Shop Gunpla | `gunpla` | Gunpla | `mk:line:gunpla` |
| Entry Grade | `entry-grade-eg` | Entry Grade | `mk:grade:eg` |
| High Grade | `high-grade-hg` | High Grade (HG) | `mk:grade:hg` |
| Real Grade | `real-grade-rg` | Real Grade (RG) | `mk:grade:rg` |
| Perfect Grade | `perfect-grade-pg` | Perfect Grade (PG) | `mk:grade:pg` |
| Master Grade | `master-grade-mg` | Master Grade (MG) | OR `mk:grade:mg`, `mgex`, `mgsd` |
| MG | `mg-standard` | MG | `mk:line:mg-standard` |
| MG Ver.Ka | `mg-ver-ka` | MG Ver.Ka | `mk:subline:ver_ka` |
| MGEX | `mgex` | MGEX | `mk:grade:mgex` |
| MGSD | `master-grade-sd-mgsd` | Master Grade SD (MGSD) | `mk:grade:mgsd` |
| SD Gundam | `sd-gundam` | SD Gundam | `mk:grade:sd` |
| SD EX-Standard | `sd-ex-standard` | SD EX-Standard | OR subline/grade `ex_standard` |
| SD Cross Silhouette | `sd-cross-silhouette` | SD Cross Silhouette | OR subline/grade `cross_silhouette` |
| SD World Heroes | `sd-world-heroes` | SD World Heroes | OR subline/grade `sdw` |
| SD BB Senshi | `sd-bb-senshi` | SD BB Senshi | OR subline/grade `bb_senshi` |
| SD G Generation | `sd-g-generation` | SD G Generation | OR subline/grade `g_generation` |
| SD Build Fighters | `sd-build-fighters` | SD Build Fighters | OR subline/grade `sdbf` |
| Gunpla-kun | `sd-gunpla-kun` | Gunpla-kun | `mk:subline:gunpla_kun` |
| Action bases | `action-base` | Action Bases | `mk:line:action_base` |
| Gunpla option parts | `gunpla-option-parts` | Gunpla Option Parts | `mk:line:gunpla_option_parts` (OPTION PARTS SET GUNPLA, MS HAND, MS SIGHT, Wave Option System) |
| HG → UC | `hg-universal-century` | HG Universal Century | OR subline/grade `hguc` |
| HG → SEED | `hg-gundam-seed` | HG Gundam SEED | OR subline/grade `hgce` |
| HG → AC | `hg-after-colony` | HG After Colony | OR subline/grade `hgac` |
| HG → IBO | `hg-iron-blooded-orphans` | HG Iron-Blooded Orphans | OR subline/grade `hgibo` |
| HG → Build Fighters | `hg-build-fighters` | HG Build Fighters | OR subline/grade `hgbf` |
| HG → Build Divers | `hg-build-divers` | HG Build Divers | OR subline/grade `hgbd` |
| Series → UC | `gundam-universal-century` | Gundam Universal Century | OR UC timeline `mk:series:*` + `mk:subline:hguc` + Unicorn + Hathaway + Reconguista |
| Series → AU hub | `gundam-alternate-universes` | Gundam Alternate Universes | OR non-UC Gundam `mk:series:*` (mega-menu parent only) |
| Series → other UC (nav ≤5 kits) | `gundam-other-uc-series` | Other UC series | OR F91, 08th MS Team, Origin, Thunderbolt, Narrative, Sentinel, Reconguista, Requiem, misc UC tags |
| Series → other AU (nav ≤5 kits) | `gundam-other-au-series` | Other AU series | OR After War Gundam X (and future small AU shelves) |
| Series → 0079 | `gundam-mobile-suit-gundam` | Mobile Suit Gundam | `mk:series:mobile_suit_gundam` |
| Series → Zeta | `gundam-zeta` | Zeta Gundam | `mk:series:zeta_gundam` |
| Series → ZZ | `gundam-zz` | Gundam ZZ | `mk:series:gundam_zz` |
| Series → CCA | `gundam-chars-counterattack` | Char's Counterattack | `mk:series:char_s_counterattack` |
| Series → 0080 | `gundam-0080` | Gundam 0080: War in the Pocket | `mk:series:gundam_0080__war_in_the_pocket` |
| Series → F91 | `gundam-f91` | Gundam F91 | `mk:series:gundam_f91` |
| Series → 0083 | `gundam-0083` | Gundam 0083: Stardust Memory | `mk:series:gundam_0083__stardust_memory` |
| Series → 08th MS | `gundam-08th-ms-team` | The 08th MS Team | `mk:series:the_08th_ms_team` |
| Series → Origin | `gundam-the-origin` | Gundam: The Origin | `mk:series:gundam__the_origin` |
| Series → Thunderbolt | `gundam-thunderbolt` | Gundam Thunderbolt | `mk:series:gundam_thunderbolt` |
| Series → Narrative | `gundam-narrative` | Gundam Narrative | `mk:series:gundam_narrative` |
| Series → Sentinel | `gundam-sentinel` | Gundam Sentinel | `mk:series:gundam_sentinel` |
| Series → Unicorn | `gundam-unicorn` | Gundam Unicorn | `mk:series:gundam_unicorn` |
| Series → SEED | `gundam-seed` | Gundam SEED | OR series seed/destiny/freedom/astray/stargazer |
| Series → Wing | `gundam-wing` | Gundam Wing | OR series wing / endless waltz |
| Series → 00 | `gundam-00` | Gundam 00 | `mk:series:gundam_00` |
| Series → G | `g-gundam` | G Gundam | `mk:series:g_gundam` |
| Series → Build Fighters | `gundam-build-fighters` | Gundam Build Fighters | `mk:series:gundam_build_fighters` |
| Series → Build Divers | `gundam-build-divers` | Gundam Build Divers | OR divers / re:rise / metaverse / battlogue |
| Series → Age | `gundam-age` | Gundam Age | `mk:series:gundam_age` |
| Series → Hathaway | `gundam-hathaway` | Gundam Hathaway's Flash | OR `mk:series:gundam_hathaway` |
| Series → IBO | `gundam-iron-blooded-orphans` | Gundam Iron-Blooded Orphans | `mk:series:iron_blooded_orphans` |
| Series → Mercury | `gundam-witch-from-mercury` | Gundam The Witch from Mercury | OR series witch / gquuuuuux |
| Series → Reconguista | `gundam-reconguista-in-g` | Gundam Reconguista in G | `mk:series:gundam_reconguista_in_g` |
| Series → Gundam X | `gundam-x` | After War Gundam X | `mk:series:gundam_x` |
| Series → Requiem | `gundam-requiem-for-vengeance` | Gundam: Requiem for Vengeance | `mk:series:gundam__requiem_for_vengeance` |
| Patlabor | `patlabor` | Patlabor | `mk:series:patlabor` |
| Macross Delta | `macross-delta` | Macross Delta | `mk:series:macross_delta` |
| Armored Trooper Votoms | `armored-trooper-votoms` | Armored Trooper Votoms | `mk:series:armored_trooper_votoms` |
| Mazinger | `mazinger` | Mazinger | `mk:series:mazinger` |
| Getter Robo | `getter-robo` | Getter Robo | `mk:series:getter_robo` |
| Kotetsu Jeeg | `kotetsu-jeeg` | Kotetsu Jeeg | `mk:series:kotetsu_jeeg` |
| Super Robot Wars | `super-robot-wars` | Super Robot Wars (crossover originals) | `mk:series:super_robot_wars` |
| Armored Core | `armored-core` | Armored Core | `mk:series:armored_core` |
| Doraemon | `doraemon` | Doraemon | `mk:series:doraemon` |
| Sakura Wars | `sakura-wars` | Sakura Wars | `mk:series:sakura_wars` |
| Linebarrels of Iron | `linebarrels-of-iron` | Linebarrels of Iron | `mk:series:linebarrels_of_iron` |
| Other series (nav: **Others**, &lt;5 kits each) | `other-series` | Other series | OR doraemon, mazinger, getter, jeeg, patlabor, macross, votoms, sakura, linebarrels, eureka, one piece |
| 30MM | `30-minutes-missions` | 30 Minutes Missions | `mk:grade:30mm` |
| 30MM Armored Core (line filter) | `30-minutes-armored-core` | 30 Minutes Missions Armored Core | `mk:line:30mm_armored_core` |
| 30MS | `30-minutes-sisters` | 30 Minutes Sisters | `mk:grade:30ms` |
| 30MF | `30-minutes-fantasy` | 30 Minutes Fantasy | `mk:grade:30mf` |
| 30MP | `30-minutes-preference` | 30 Minutes Preference | `mk:grade:30mp` |
| 30MM option parts | `30-minutes-accessories` | 30 Minutes Option Parts | `mk:line:30mm_accessories` |
| Pokémon | `pokemon` | Pokémon | `mk:grade:pokemon` |
| Kotobukiya | `kotobukiya` | Kotobukiya | `mk:grade:kotobukiya` |
| Moderoid | `moderoid` | MODEROID | `mk:line:moderoid` |
| Keroro | `keroro` | Keroro | `mk:grade:keroro` |
| SNAA | `snaa` | SNAA | `mk:line:snaa` |
| One Piece | `one-piece` | One Piece | `mk:line:one_piece` |
| Eureka Seven | `eureka-seven` | Eureka Seven | `mk:line:eureka_seven` |
| MechatroWeGo | `mechatrowego` | MechatroWeGo | `mk:line:mechatrowego` |
| PLAMAX | `plamax` | PLAMAX | `mk:line:plamax` |
| Evangelion | `evangelion` | Evangelion | `mk:line:evangelion` (excludes CCS Toys → Miscellaneous nav later) |

**Exception:** `/collections/beginner-kits` — Shopify price rule, not `mk:*`. Mega menu only.

**Theme:** `ovs-shopify-theme/snippets/ovs-model-kits-mega-menu-poc.liquid` — all links above use `/collections/{handle}`.

**Mega menu — Gundam nesting:** under **Gundam**, **Universal Century** and **Alternate universes** are **collapsed by default** (`<details>`); click the row chevron to expand series inline. Hub title links (`gundam-universal-century` / `gundam-alternate-universes`) navigate without toggling. Series shelves with **>5 kits** get their own link; **≤5 kits** roll into **Other UC/AU series**.

**Mega menu — grade + Others nesting:** **SD Gundam**, **High Grade**, and **Master Grade** use the same collapsed `<details>` pattern. **Others** (non-Gundam franchises with &lt;5 kits each) expands to individual shelf links (Doraemon, Mazinger, Patlabor, etc.); hub link → `other-series`. **Option parts** (Gunpla) and **Action bases** are flat links at the bottom of the grade column (`gunpla-option-parts`, `action-base`).

**Mega menu — Other model kits column order:** static (not dynamic). Sorted by ERP **total sold** (`received qty − available`) as of **2026-08-26**: Pokémon → Kotobukiya → Keroro → Evangelion → Moderoid → Eureka Seven → PLAMAX → One Piece → SNAA → MechatroWeGo. Re-run shelf popularity when reordering (see `scripts/tmp-other-mk-shelf-popularity.php` pattern or Products `total_sold` rollup).

---

## Operator workflow

```powershell
# 1. After ERP taxonomy changes — push tags
docker exec pricing-tool-php php artisan products:push-model-kit-tags

# 2. Upsert smart collections from ModelKitShelfCatalog
docker exec pricing-tool-php php artisan products:model-kit-shelf-collections

# 3. Push mega menu (AI Dev theme)
cd ovs-shopify-theme
npx @shopify/cli theme push --theme 196218716241 --only snippets/ovs-model-kits-mega-menu-poc.liquid
```

Adding a shelf: extend `ModelKitShelfCatalog` → push tags → run shelf command → wire liquid → browser QA.

---

## Code map

| Piece | Path |
| --- | --- |
| Shelf registry | `app/Support/Products/Storefront/ModelKitShelfCatalog.php` |
| Tag resolver | `app/Support/Products/Storefront/ModelKitStorefrontTagResolver.php` |
| Collection upsert | `ShopifyStorefrontPilotCollectionService::ensureModelKitShelfCollections()` |
| Shelf CLI | `app/Console/Commands/ProductsModelKitShelfCollectionsCommand.php` |
| Tag push CLI | `app/Console/Commands/ProductsPushModelKitTagsCommand.php` |
| Mega menu | `ovs-shopify-theme/snippets/ovs-model-kits-mega-menu-poc.liquid` |

# Application features (Web UI + flows)

This folder is the **navigation hub** for **what each screen does**, how users move through workflows, and which APIs/services back them.

| If you need… | Read… |
| --- | --- |
| **Whole-app shell, nav links, roles** | [shared/shell-and-navigation.md](shared/shell-and-navigation.md), [shared/roles-and-access.md](shared/roles-and-access.md) |
| **Products (catalog, imports, exports, drawers, bulk)** | [screens/products.md](screens/products.md) |
| **Product taxonomy research and verification** | [screens/products.md#canonical-taxonomy-review-productstaxonomy](screens/products.md#canonical-taxonomy-review-productstaxonomy) |
| **Purchase orders** | [screens/purchase-orders.md](screens/purchase-orders.md) |
| **Custom orders (Asia request + quote)** | [screens/custom-asia-orders.md](screens/custom-asia-orders.md) |
| **Admin inventory CSV + sessions + employee count** | [screens/inventory-and-counting.md](screens/inventory-and-counting.md) |
| **Competitor pricing** | [screens/price-research.md](screens/price-research.md) |
| **Staff orders (POS / channels)** | [screens/staff-orders-report.md](screens/staff-orders-report.md) |
| **Reports hub (inventory by type, etc.)** | [screens/reports.md](screens/reports.md) |
| **PDP / Plamod rename batch progress** | [screens/sync-progress.md](screens/sync-progress.md) |
| **Bandai TCG+ events** | [screens/tcg-events.md](screens/tcg-events.md) |
| **Plamod preorders** | [screens/preorders.md](screens/preorders.md) |
| **PLAMOD restock (in-stock)** | [screens/plamod-restock.md](screens/plamod-restock.md) |
| **Maintenance & dangerous ops** | [screens/maintenance.md](screens/maintenance.md) |
| **Full HTTP/job/service catalog** (dense backend reference) | [backend/system-catalog-services-and-http.md](backend/system-catalog-services-and-http.md) |

Formal requirements/specs remain in **`docs/requirements/`** and **`docs/project-overview.md`**.

Shopify **Online Store theme** workflows (draft theme, static About page handoff—not this Laravel bundle) live in **`docs/shopify-theme/`**.

## Feature finder (alphabetical)

| Topic | Doc |
| --- | --- |
| AliExpress cookies (maintenance) | [screens/maintenance.md](screens/maintenance.md) |
| Database backup / restore | [screens/maintenance.md](screens/maintenance.md) |
| Employee inventory counting | [screens/inventory-and-counting.md](screens/inventory-and-counting.md) |
| External login / roles | [shared/roles-and-access.md](shared/roles-and-access.md) |
| Job batches / PDP sync progress | [screens/sync-progress.md](screens/sync-progress.md) |
| Navbar & layout | [shared/shell-and-navigation.md](shared/shell-and-navigation.md) |
| Price research & reports | [screens/price-research.md](screens/price-research.md) |
| Staff orders report | [screens/staff-orders-report.md](screens/staff-orders-report.md) |
| Reports hub | [screens/reports.md](screens/reports.md) |
| Products catalog (table, bulk, tabs) | [screens/products.md](screens/products.md) |
| Product taxonomy review | [screens/products.md#canonical-taxonomy-review-productstaxonomy](screens/products.md#canonical-taxonomy-review-productstaxonomy) |
| Purchase orders | [screens/purchase-orders.md](screens/purchase-orders.md) |
| Custom orders (Asia) | [screens/custom-asia-orders.md](screens/custom-asia-orders.md) |
| Shopify / exports / tunnel (UI, internal catalog) | [screens/products.md](screens/products.md), [screens/sync-progress.md](screens/sync-progress.md) |
| Shopify storefront static pages / theme duplication | [`../shopify-theme/static-content-pages-workflow-and-about-us.md`](../shopify-theme/static-content-pages-workflow-and-about-us.md) |
| Storefront content hierarchy (guides, mega menu CTAs) | [`../requirements/storefront-content-hierarchy.md`](../requirements/storefront-content-hierarchy.md) |
| Model kit shelves (`mk:*` tags → smart collections) | [`../requirements/model-kit-storefront-shelves.md`](../requirements/model-kit-storefront-shelves.md) |
| Stuck price run reset | [screens/maintenance.md](screens/maintenance.md) |
| TCG+ events | [screens/tcg-events.md](screens/tcg-events.md) |
| Plamod preorders | [screens/preorders.md](screens/preorders.md) |
| PLAMOD restock | [screens/plamod-restock.md](screens/plamod-restock.md) |

## Screen ↔ route cheat sheet

| Route | Vue page |
| --- | --- |
| `/products` (+ hash tabs) | `resources/js/pages/ProductsPage.vue` |
| `/products/taxonomy` | `resources/js/pages/ProductTaxonomyReviewPage.vue` |
| `/purchase-orders` | `PurchaseOrdersPage.vue` |
| `/purchase-orders/:id` | `PurchaseOrderDetailPage.vue` |
| `/purchase-orders/:id/beta` | `PurchaseOrderDetailBetaPage.vue` (beta workspace; classic remains default) |
| `/custom-orders/asia` | `CustomAsiaOrdersPage.vue` |
| `/custom-orders/asia/new`, `/custom-orders/asia/:id` | `CustomAsiaOrderDetailPage.vue` |
| `/inventory-check` | `InventoryCheckPage.vue` |
| `/inventory-check/:id` | `InventoryCheckDetailPage.vue` |
| `/employee/inventory-count` | `EmployeeInventoryCountPage.vue` |
| `/price-research` | `PriceResearchPage.vue` |
| `/price-research/reports` | `PriceResearchReportsPage.vue` |
| `/price-research/runs/:id/logs` | `PriceResearchRunLogsPage.vue` |
| `/reports/staff-orders` | `StaffOrdersReportPage.vue` (nested in `ReportsLayoutPage.vue`) |
| `/reports/inventory-by-main-type` | `InventoryByMainTypeReportPage.vue` (nested in `ReportsLayoutPage.vue`) |
| `/sync-progress` | `SyncProgressPage.vue` |
| `/tcg-events` | `TcgEventsPage.vue` |
| `/preorders` | `PreordersPage.vue` |
| `/restocking/plamod` | `PlamodRestockPage.vue` |
| `/maintenance` | `MaintenancePage.vue` |
| `/shopify/webhooks` | `ShopifyWebhookLogsPage.vue` |

## Source files worth knowing

- **`resources/js/router.ts`** — route table + employee guard.
- **`resources/js/components/App.vue`** — wraps `RouterView`; **Pricing** (`/price-research`) gets full-width layout (`max-w-none`).
- **`resources/js/components/AppNav.vue`** — top nav (`RouterLink`s); employee role only sees **Inventory Count**.

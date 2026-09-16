# Shell & layout (`App.vue`)

**Files:** `resources/js/components/App.vue`, `resources/js/components/AppNav.vue`, `resources/views/app.blade.php`.

## Laravel HTML shell

- `app.blade.php` mounts `<div id="app">`, injects **`meta[name=external-access-role]`** (`admin` or `employee`) from the request attribute Laravel sets during the external-access middleware pass.
- Vite bundles `resources/js/app.ts` → root component **`App.vue`**.

## Vue root layout

- **Background:** `employeeInventoryScanNotFoundBg` reactive flag can force **full-page red background** during employee scanning when last scan resolves to an “unknown barcode” issue row (paired with **`AppNav`** red header).
- **Nav:** **`AppNav`** is rendered above page content except on standalone chrome routes (`/purchase-orders/:id/beta`). It stays pinned (`sticky top-0`) while the page scrolls and sets `--app-nav-height` for grids that pin column headers below it.
- **Content width:**
  - **Default:** `main` is `max-w-screen-2xl` with horizontal padding (`px-4 py-6`).
  - **Pricing / price research:** `route.path.startsWith('/price-research')` → **`max-w-none`** so the competitor table can use full viewport width.
  - **PO beta workspace:** `isStandaloneAppChromePath` → no `AppNav`, `main` has no padding (`max-w-none px-0 py-0`).

## Top navigation (admin)

When `currentAccessRole() !== 'employee'`, **`ADMIN_NAV_ENTRIES`** in `resources/js/lib/navCatalog.ts` drives grouped top nav (`AppNav.vue` + `AppNavDropdown.vue`):

| Top label | Children / target |
| --- | --- |
| **Catalog** (dropdown) | Products → `/products`; Store preorders → `/store-preorders`; Taxonomy → `/products/taxonomy`; Inventory Check → `/inventory-check`; Pricing → `/price-research` |
| **Procurement** (dropdown) | Purchase Orders → `/purchase-orders`; Special Order → `/special-orders`; Plamod preorders → `/preorders`; Plamod Restock → `/restocking/plamod` |
| **Sales** | `/orders` (Shopify sales list — see [screens/shopify-orders.md](../screens/shopify-orders.md)) |
| **Events** (dropdown) | Store events → `/store-events`; Marketing notes → `/marketing-notes`; TCG Events → `/tcg-events` |
| **Reports** | `/reports/staff-orders` (active for any `/reports/*`; sidebar lists all reports — see [screens/reports.md](../screens/reports.md)) |
| **System** (dropdown) | Maintenance → `/maintenance`; Sync progress → `/sync-progress`; Webhook logs → `/shopify/webhooks` |

## Top navigation (employee)

- Only **Inventory Count** → `/employee/inventory-count`.
- Header uses the same red styling as full-page when “not found” scan context is active (see `employeeInventoryScanUi`).

## Entry redirects (`router.ts`)

- `/` → employees land on **`/employee/inventory-count`**; admins on **`/products`**.
- `/import` → **`/products#import`** (opens Products page with import hash).

## Deep links not in the top nav bar

- **`/price-research/reports`** and **`/price-research/runs/:id/logs`** — linked from the Pricing page UI.
- **`/purchase-orders/:id/beta`** — opt-in PO workspace; hides `AppNav` and uses its own masthead. Classic `/purchase-orders/:id` stays the default.

**System** dropdown also lists **`/sync-progress`** and **`/shopify/webhooks`** (still reachable from Maintenance cards and in-page links).

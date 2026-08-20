# Shell & layout (`App.vue`)

**Files:** `resources/js/components/App.vue`, `resources/js/components/AppNav.vue`, `resources/views/app.blade.php`.

## Laravel HTML shell

- `app.blade.php` mounts `<div id="app">`, injects **`meta[name=external-access-role]`** (`admin` or `employee`) from the request attribute Laravel sets during the external-access middleware pass.
- Vite bundles `resources/js/app.ts` → root component **`App.vue`**.

## Vue root layout

- **Background:** `employeeInventoryScanNotFoundBg` reactive flag can force **full-page red background** during employee scanning when last scan resolves to an “unknown barcode” issue row (paired with **`AppNav`** red header).
- **Nav:** **`AppNav`** is always rendered above page content (except SPA routes still mount inside same tree).
- **Content width:**
  - **Default:** `main` is `max-w-screen-2xl` with horizontal padding (`px-4 py-6`).
  - **Pricing / price research:** `route.path.startsWith('/price-research')` → **`max-w-none`** so the competitor table can use full viewport width.

## Top navigation (admin)

When `currentAccessRole() !== 'employee'`, links appear in this order:

1. **Products** → `/products`
2. **Inventory Check** → `/inventory-check` (active for any `/inventory-check/*`)
3. **Purchase Orders** → `/purchase-orders` (active for any `/purchase-orders/*`)
4. **Pricing** → `/price-research` (label “Pricing”; path is still `price-research`)
5. **Preorders** → `/preorders`
6. **Restock** → `/restocking/plamod` (PLAMOD in-stock restock proposal)
7. **TCG Events** → `/tcg-events`
8. **Reports** → `/reports/staff-orders` (active for any `/reports/*`; sidebar lists all reports — see [screens/reports.md](../screens/reports.md))
9. **Maintenance** → `/maintenance`

## Top navigation (employee)

- Only **Inventory Count** → `/employee/inventory-count`.
- Header uses the same red styling as full-page when “not found” scan context is active (see `employeeInventoryScanUi`).

## Entry redirects (`router.ts`)

- `/` → employees land on **`/employee/inventory-count`**; admins on **`/products`**.
- `/import` → **`/products#import`** (opens Products page with import hash).

## Deep links not in the nav bar

- **`/sync-progress`** — opened from in-page links (Products “Sync progress”, batch deep links) and after some bulk flows (see [`screens/sync-progress.md`](../screens/sync-progress.md)).
- **`/price-research/reports`** and **`/price-research/runs/:id/logs`** — linked from the Pricing page UI.

# NT Sales, Employee Authentication, and COGS

**Status:** Draft requirements; implementation not started  
**Last updated:** 2026-08-19

## 1. Goals

Build an employee-facing NT checkout workflow and an admin-facing sales/COGS view while preserving the current operational quantity-maintenance workflow.

The system must:

1. Give each employee an email/password account with securely hashed credentials.
2. Let employees scan products into one open NT cart, apply a manual discount, review the before-tax total, clear the cart, and submit it.
3. Keep NT sales entirely inside the ERP. Shopify must never receive an NT order or draft order.
4. Adjust Shopify inventory for an NT sale by the sold delta only, without using or overwriting the ERP available quantity.
5. Calculate and store cost at time of sale in the ERP for NT and Shopify sales.
6. Give admins a unified list of NT and mirrored Shopify orders, including COGS and margin.
7. Provide a repeatable full-history COGS rebuild function.

## 2. Terminology

- **NT sale:** A sale outside Shopify. It may be an explicitly scanned employee checkout or an inventory variance classified as an untracked NT sale.
- **Explicit NT sale:** An NT sale submitted through the employee checkout.
- **Variance NT sale:** An inventory shortage classified as a historical/untracked NT sale.
- **ERP available quantity:** `products.available_qty`; an operator-maintained working value that may intentionally be temporary.
- **Shopify quantity:** Shopify's inventory level at the configured location.
- **Landed unit cost:** CAD unit cost plus allocated shipping plus allocated surcharge.
- **COGS allocation:** ERP-only accounting data that assigns landed cost layers to sold units without changing operational quantities.

## 3. Access and authentication

### 3.1 Admin access

- Existing hardcoded/shared admin-password access remains the administrative authentication mechanism.
- Admin access continues to grant the full administrative application.
- Admins are not required to use a personal admin user account for administrative work in this scope.
- An admin performing normal employee work must log in with that person's employee account and receive the same restricted employee experience.
- Removing the shared admin password is out of scope.

### 3.2 Employee accounts

- Employees authenticate with an email address and password.
- Passwords must use Laravel's secure one-way hashed password handling; plaintext passwords must never be stored or logged.
- There is no registration, forgot-password, email-verification, or employee self-service password-reset flow.
- Admins can create employees, deactivate/reactivate employees, and set/reset employee passwords.
- An inactive employee cannot log in or submit a sale.
- The temporary legacy shared employee-password path may remain during migration, but an attributed NT sale requires an authenticated employee account.

### 3.3 Employee authorization and privacy

Employees may access only:

- Employee inventory scanning.
- Employee NT checkout.
- Login/logout.
- Product image endpoints needed by those scan screens.

Employees must not access:

- Any order-list or order-detail route/API.
- Their own historical sales.
- Another employee's sales.
- COGS, margin, Shopify synchronization details, internal sale identifiers, or NT reference numbers.

The server must enforce these restrictions. Hiding links or fields in Vue is not sufficient.

## 4. Employee NT checkout

### 4.1 Cart lifecycle

- Each employee has at most one open NT cart.
- Returning to the checkout restores that employee's open cart from the database.
- The employee can clear the cart after an explicit confirmation.
- Clearing a cart removes its draft lines and does not create a completed sale.
- Submitting a cart atomically completes it and leaves the employee with a new empty cart.

### 4.2 Scanning and lines

- Product lookup uses the scanned barcode.
- Each successful scan adds exactly one unit.
- Repeated scans of the same product increment the existing cart line.
- Each line shows customer-useful information only: product image, description, SKU where operationally useful, quantity, selling price, and line total.
- A missing/unknown barcode uses the established employee-scan error treatment.
- The selling price is the current ERP `product_selling_prices.selling_price` and is snapshotted on the cart/sale line.
- A product without a selling price cannot be submitted; the UI must identify the affected line.
- Overselling is permitted; checkout does not block based on ERP or Shopify quantity.

### 4.3 Discount and totals

MVP discount behavior:

- One manual fixed-CAD discount amount applies to the whole cart.
- The default is `0.00`.
- The discount must be non-negative and cannot exceed the gross subtotal.
- The UI shows gross subtotal, manual discount, and net subtotal before tax.
- No tax is calculated or stored as part of this checkout.
- The discount is allocated proportionally across lines for line-level net revenue and margin reporting.
- Discount allocation must use decimal/money-safe arithmetic and deterministic remainder handling.
- Discount does not change COGS.

### 4.4 Submit confirmation and employee result

- Submit requires an immediate confirmation showing item count and net subtotal before tax.
- A successful submission shows a generic success result and clears the screen for the next sale.
- The employee response and UI must not expose the internal UUID or human-readable NT reference.
- Failed submission leaves the cart available for correction/retry and must not create a partial completed sale.

## 5. NT sale identity and records

- Completed explicit NT sales receive sequential references beginning with **`NT-001000`**.
- Reference allocation must be concurrency-safe and unique.
- References are visible only to admins.
- Public/API identifiers remain UUIDs; internal database IDs remain numeric.
- Completed sales and their financial/cost snapshots are immutable through the employee workflow.
- A completed sale records the employee user, completion time, gross subtotal, discount, net before-tax subtotal, COGS, and costing status.
- Sale lines record product/SKU/barcode/name snapshots, quantity, gross unit selling price, allocated discount, net revenue, and COGS.
- Completed NT sales are not physically deleted. An admin “delete” action is implemented as an audited void/undo so the reference, original lines, employee attribution, and reversal history remain available.
- Voided NT references are never reused.
- The void/undo is financially a full NT refund: it records an equal and opposite revenue transaction instead of merely hiding or deleting the original revenue.

## 6. Quantity behavior

Operational quantity and COGS are intentionally separate.

### 6.1 Explicit NT sale

When an NT sale is submitted:

- **Do not change `products.available_qty`.**
- If ERP available is temporarily `100` and two units are sold, ERP available remains `100`.
- Do not create or update a Shopify order, draft order, customer, payment, or sales record.
- Queue a Shopify inventory delta of `-2` for the affected inventory item/location.
- The Shopify adjustment must not read ERP available quantity to determine the new Shopify quantity.

### 6.2 Shopify order

When a Shopify order is mirrored or updated:

- Do not change `products.available_qty`.
- Do not send an inventory update back to Shopify; Shopify already processed its sale.
- Calculate/update ERP-only COGS allocations for eligible order lines.

### 6.3 Manual quantity maintenance

- Existing explicit Shopify-to-ERP pulls and ERP-to-Shopify absolute quantity pushes remain operator-controlled.
- A manual absolute ERP-to-Shopify push is the only workflow that intentionally replaces Shopify quantity with the operator's chosen ERP value.
- NT and Shopify order processing must never trigger that absolute push.

### 6.4 Shopify NT delta reliability

- Use Shopify's inventory delta-adjustment capability, not `productSet` absolute `inventoryQuantities`.
- Queue the adjustment only after the ERP NT sale transaction commits.
- Persist one idempotent adjustment record per NT sale line/inventory item/location.
- Retries must never apply the same decrement twice.
- Retry with bounded exponential backoff and jitter.
- Admins can see pending, succeeded, and failed adjustment states and retry a failed adjustment.
- A Shopify failure does not reverse or delete the completed ERP NT sale.
- Shopify access tokens and mutation payload secrets remain backend-only and masked in logs.

### 6.5 Admin void/undo quantity reversal

- Only an admin can void/undo a completed explicit NT sale.
- The action requires an immediate destructive-action confirmation showing the NT reference, sold units, and the Shopify quantity effect.
- Voiding an NT sale does not change `products.available_qty`.
- If the original Shopify decrement succeeded, queue the exact inverse Shopify delta (`+quantity`) for each affected inventory item/location.
- If the original decrement is still pending and has definitely not been sent, cancel it and do not enqueue an inverse adjustment.
- Original and reversal adjustments must be serialized per sale line/inventory item/location so an in-flight decrement cannot race its reversal.
- Unknown/ambiguous Shopify outcomes must be reconciled before applying another delta; the system must never guess and risk applying the quantity twice.
- The reversal uses its own persisted idempotency key and retry status. Retrying the void or its job must never restore quantity twice.
- A completed void records who voided it, when it was voided, and an optional reason.
- A sale remains `void_pending` while required Shopify reversals are pending, becomes `voided` after successful/cancelled reversals, and becomes `void_failed` when intervention is required.
- A failed Shopify reversal does not erase the original sale or its audit trail; admins can see and retry the failure.
- Variance NT records do not automatically restore Shopify quantity because their creation did not send an NT Shopify decrement. Undoing a variance classification reverses only its ERP reporting/COGS classification.

### 6.6 NT financial refund

- Voiding/undoing an explicit NT sale creates an ERP-only full refund linked to the original NT sale.
- Partial refunds are out of scope.
- The refund reverses the original gross revenue, allocated discount, net revenue before tax, COGS, and gross profit.
- The refund amount is based on the immutable original sale snapshots; current product prices or costs must not be used.
- Net reporting effect across the original sale and its refund is zero revenue, zero COGS, and zero gross profit.
- The original sale remains visible to admins with its original positive values; the linked refund is visible with equal-and-opposite financial values.
- The refund timestamp and initiating admin are retained for audit.
- Date-based reporting recognizes the sale on its completion date and the negative refund on the refund date; all-time reporting nets them to zero.
- Shopify receives only the inventory `+quantity` reversal. No Shopify refund, order, transaction, payment, or customer record is created.
- Financial refund creation is committed in the ERP before its Shopify inventory reversal is queued.
- Repeating the void request must return the existing refund/reversal state and must never create a second financial refund.

## 7. Canonical landed unit cost

### 7.1 Definition

Every COGS and landed-cost feature must use:

```text
landed unit cost (CAD)
    = CAD purchase-order line unit cost
    + allocated shipping per unit
    + allocated surcharge per unit
```

Allocation must:

- Include every line on the selected purchase order.
- Use the established received-versus-ordered unit rules in `PurchaseOrderAllocation`.
- Treat an explicit shipping or surcharge total of `0.00` as valid.
- Preserve the established foreign-currency-to-CAD unit-cost resolution.

### 7.2 Single source of calculation

- Landed unit cost calculation must not be duplicated across services, SQL fragments, reports, lot maintenance, or COGS.
- `PurchaseOrderLandedUnitCostResolver`, composed with `PurchaseOrderItemCadUnitCostResolver` and `PurchaseOrderAllocation`, is the current canonical starting point because it includes both shipping and surcharge.
- Implementation must extract/reuse a shared landed-cost allocation component where required so all consumers use the same semantics and rounding.
- `products.latest_landed_unit_cost` is a cache/result, not an independent formula.
- Raw `inventory_lots.unit_cost + inventory_lots.shipping_per_unit` is not sufficient under the current schema because lot `shipping_per_unit` currently excludes surcharge.

### 7.3 Required landed-cost audit

Before COGS is accepted, audit every landed-cost producer and consumer, including:

- PO detail and workflow pricing.
- Latest landed-cost cache/backfill.
- Product PO-line history.
- Price Research latest and min/max landed values.
- Inventory lot creation/recalculation.
- Inventory reports.
- Inventory-check landed snapshots.
- NT and Shopify COGS allocation/backfill.

Any duplicate formula must be replaced with the shared calculation or explicitly documented as a different semantic. Regression tests must prove surcharge is included consistently.

## 8. ERP-only COGS ledger

### 8.1 Separation from operational quantity

- COGS allocation must not depend on or mutate `products.available_qty`.
- COGS allocation must not mutate Shopify inventory.
- Cost-layer balances used for sales costing must be separate from temporary operational quantity values and from physical inventory-adjustment state.
- Quantity maintenance can therefore remain temporary without orders overwriting it or changing cost-at-sale records.

### 8.2 Forward COGS

Calculate COGS for:

- Every newly completed explicit NT sale.
- Every newly mirrored or materially changed eligible Shopify order.
- Variance NT sales once classified under the reconciliation rules below.

For each line:

- Allocate FIFO landed costs from ERP PO receipt history.
- Snapshot every allocation used by the sale line.
- Include surcharge through the canonical landed-cost component.
- If FIFO history is insufficient, use `products.latest_landed_unit_cost` as an estimated fallback.
- Record fallback quantity/value and mark the line/order COGS as estimated.
- Missing SKU/product mappings remain visible to admins and are never silently valued at zero.
- Cancellations/voids use the existing Shopify demand-eligibility rules and must not retain eligible COGS.
- Voiding an explicit NT sale reverses its active revenue and COGS contribution without erasing the original snapshots.
- Because a historical void can change FIFO allocation for later sales, it triggers a product-scoped chronological COGS rebuild for every affected product.

## 9. Inventory variance as NT sales

- A negative inventory-count variance may be classified as an NT sale because historical NT sales were previously untracked.
- Variance-derived sales are marked with source `inventory_variance` and remain distinguishable from explicit scanned NT sales.
- Variance-derived records may receive internal NT references for admin audit, but references are never shown to employees.
- A variance has no directly observed selling transaction. Revenue/discount attribution must therefore be marked estimated or unknown rather than silently treated as a scanned checkout.
- Its COGS may be calculated from FIFO landed cost.

### 9.1 Double-counting guard

Raw negative inventory variance must not automatically consume COGS a second time for units already represented by:

- A mirrored Shopify order.
- An explicit NT sale.
- Another finalized inventory adjustment.

The reconciliation/backfill process must identify the inventory-check interval and account for known Shopify and explicit NT quantities before recognizing residual variance as an untracked NT sale. If the available snapshots or interval provenance are insufficient to reconcile safely, the run must flag the variance for admin review instead of guessing.

## 10. Admin orders and profitability

Admins receive a unified, admin-only orders list containing explicit/variance NT sales and mirrored Shopify orders.

Required fields:

- Date/time.
- Source/type.
- Admin-visible reference.
- Employee or Shopify staff attribution where known.
- Gross subtotal.
- Discount.
- Net subtotal before tax.
- COGS.
- Gross profit.
- COGS estimated/missing indicator.
- NT Shopify inventory-adjustment status.
- NT sale state, including completed, void pending, voided, and void failed.

Requirements:

- All sortable data columns are sortable.
- Filters include date range, source/type, employee/staff, COGS status, and NT Shopify-adjustment status.
- Admin order details show line-level revenue, discount allocation, COGS allocations, and estimation reasons.
- Admins can void/undo an eligible completed explicit NT sale from its order detail.
- Voided sales remain visible for audit and show their original values, linked equal-and-opposite refund, and Shopify reversal status.
- Revenue, COGS, and gross-profit reports must include the linked refund so the combined net contribution of a fully refunded NT sale is zero.
- Shopify data is read from the local mirror; opening the list must not perform live Shopify pagination.
- Employee accounts have no authorization to these APIs or pages.

## 11. Repeatable historical COGS rebuild

### 11.1 Rebuild function

Provide an idempotent application service that can rebuild the entire COGS history whenever requested.

The same service must be callable from:

- A thin Artisan command.
- An admin maintenance action with confirmation and progress/status.

The rebuild must:

1. Start a persisted run record.
2. Rebuild generated cost layers/allocations from authoritative PO and sales history in chronological order.
3. Include eligible Shopify order lines, non-voided explicit NT sales, and safely reconciled variance NT sales.
4. Use the canonical landed-cost calculation, including surcharge.
5. Replace prior generated COGS allocations atomically or by resumable staged swap.
6. Be deterministic for unchanged source data.
7. Record counts, durations, missing product mappings, estimated quantities/value, reconciliation warnings, and failures.
8. Support safe retry/resume after interruption.

The rebuild must never:

- Change `products.available_qty`.
- Push or adjust Shopify inventory.
- Create an NT order in Shopify.
- Modify authoritative PO, Shopify order mirror, or completed NT sale financial snapshots.

### 11.2 Incremental consistency

- Forward COGS and full rebuild use the same allocation engine.
- A late-arriving or historically changed Shopify order triggers a product-scoped chronological COGS rebuild where appending would produce incorrect FIFO order.
- Re-running the full rebuild is always available as the recovery path.

## 12. Conceptual data boundaries

Implementation naming may follow established repository conventions, but the model must represent:

- Employee user identity and active status.
- NT sale header and immutable sale lines.
- Concurrency-safe NT reference sequence.
- ERP-only cost layers and per-sale-line cost allocations.
- Shopify NT inventory-delta outbox/attempt status.
- COGS rebuild run/checkpoint status.
- Variance classification/reconciliation provenance.

Money uses fixed-precision decimal storage; floats are forbidden for financial calculations.

## 13. API and transaction requirements

- All new APIs live under `/api/v1`.
- Controllers remain thin and use Form Requests and Resources.
- Business workflows live in focused services using typed DTOs.
- Complex/reused queries live in repositories.
- NT submit locks the employee's draft cart and reference sequence, validates snapshots, completes the ERP sale, writes its outbox records, and commits in one database transaction.
- No Shopify network request occurs inside the NT sale transaction.
- Authorization is enforced through middleware/policies at the API boundary and inside sensitive service operations as appropriate.
- Responses use stable error shapes and must not leak fields forbidden to employees.
- Dates are stored consistently and emitted as ISO-8601; UI display uses client-local time.

## 14. Testing and acceptance

### 14.1 Authentication and authorization

- Valid employee login/logout, invalid credentials, inactive account, secure password hashing.
- Admin employee-management happy/failure paths.
- Shared admin password remains functional.
- Employee cannot access any order/history/COGS/admin API.
- Employee cannot void/undo an NT sale or call its reversal API.
- Employee checkout payloads never expose an NT reference or internal sale identifier.

### 14.2 NT checkout

- Scan once/repeatedly, unknown barcode, missing selling price, restore one open cart, confirmed clear, discount boundaries, oversell, submit, and retry after validation failure.
- Submit leaves `products.available_qty` unchanged.
- Submit creates no Shopify order/draft order mutation.
- Submit creates one queued `-quantity` Shopify adjustment per affected item.
- Duplicate job execution does not double-adjust Shopify.
- Admin void confirms before mutation, retains the sale/reference, and leaves `products.available_qty` unchanged.
- Admin void creates exactly one linked full financial refund whose net revenue, COGS, and gross-profit effect offsets the original sale.
- A successfully applied NT decrement is restored with exactly one `+quantity` Shopify adjustment.
- A definitely unsent decrement is cancelled without sending a compensating increment.
- Repeated void requests and repeated reversal-job execution do not restore Shopify quantity twice.
- In-flight/ambiguous decrement outcomes cannot race a reversal.

### 14.3 COGS

- FIFO across multiple PO lots.
- CAD conversion, shipping, and surcharge are all included through the canonical resolver.
- Insufficient history uses marked estimated fallback.
- Forward and rebuild allocation results match.
- Re-running the unchanged rebuild is deterministic.
- COGS/rebuild leaves ERP and Shopify quantities unchanged.
- Late historical Shopify data is reallocated chronologically.
- Variance reconciliation does not double-count known Shopify or explicit NT units.

### 14.4 User-visible QA

- Playwright covers employee login, repeated barcode scans, discount, clear confirmation, submit, and forbidden history access.
- Playwright covers admin user management, unified order filters/details, adjustment retry, and COGS rebuild status.
- Real-browser QA must report exact URLs, actions, visible totals/statuses, and pass/fail outcomes.
- Shopify delta behavior must be verified against a safe configured test SKU/location before production handoff.

## 15. Explicitly out of scope

- Tax calculation.
- Payment processing.
- Customer records.
- Receipts/printing.
- Returns/refunds workflow beyond excluding cancelled/voided Shopify demand from COGS.
- Employee access to any historical sales.
- NT order/draft-order creation in Shopify.
- Automatic ERP available-quantity changes from NT or Shopify sales.
- Physical deletion or reference reuse for completed NT sales.
- Partial NT refunds.
- Removing the shared admin-password mechanism.
- Registration, forgot-password, or email-verification flows.

## 16. Rule compliance mapping

| Rule area | Requirement coverage |
| --- | --- |
| Architecture/controllers/services/DAL | Thin controllers, typed services/DTOs, repositories, transactions, reversible normalized schema. |
| Frontend/design | Vue Composition API, strict TypeScript, mobile-first scan flow, accessible sortable admin table, confirmation UX. |
| Authentication/security | Laravel password hashing, server authorization, no secret/forbidden-field leakage, inactive-user enforcement. |
| Shopify integration | Backend GraphQL only, explicit approved inventory mutation, idempotent outbox, retries/jitter, logging, no network in DB transaction. |
| Data-column discipline | Canonical landed semantic explicitly includes CAD unit + shipping + surcharge; caches/raw lot columns cannot replace the resolver. |
| Console | Rebuild command is a thin wrapper over the shared rebuild service with stable output/progress. |
| Testing/QA | TDD, Pest/Vitest/Playwright coverage, mocked external calls, real browser and safe Shopify integration verification. |
| Documentation | This file is authoritative requirements; implementation must update `docs/features/**`, Shopify integration docs, and HTTP catalog with actual routes/behavior. |
| Version/type/formatting | Project-pinned PHP/Laravel/MySQL/Node/Vue stack, strict types, decimals for money, Pint/Prettier and CI-parity checks. |

## 17. Implementation phases

1. Employee authentication and admin employee management while preserving shared admin access.
2. ERP-only NT cart/checkout with fixed discount and employee privacy.
3. Idempotent queued Shopify delta adjustments for explicit NT sales.
4. Canonical landed-cost consolidation and full landed-cost audit.
5. Forward COGS for explicit NT and Shopify sales.
6. Admin unified orders/profitability UI.
7. Variance NT reconciliation.
8. Repeatable full-history COGS rebuild and admin maintenance progress.


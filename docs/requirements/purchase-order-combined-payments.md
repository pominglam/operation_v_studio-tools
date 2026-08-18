# Purchase order combined payments

## Purpose

Record one CAD payment against two or more foreign-currency purchase orders without merging their
shipment records. Operators must retain the original vendor-currency product and freight totals
while seeing each air/sea PO's allocated CAD product, shipping, surcharge, and landed costs.

## Requirements

- Operators select at least two POs from Purchase Orders history and preview one combined payment.
- All selected POs use the same non-CAD vendor currency and have positive vendor product totals.
- **Products only:** allocate the CAD payment by each PO's vendor product total; leave existing CAD
  shipping totals unchanged.
- **Products + shipping:** require each PO's vendor shipping total, calculate one implied FX rate
  from the combined vendor product + freight total, and allocate CAD product and shipping amounts
  separately.
- Amount entry supports either one **Total paid** value or separate combined **Product paid** and
  **Shipping paid** CAD values. In split mode, the total is calculated automatically and each pool
  is allocated independently so the known product/freight split is preserved.
- After previewing the suggested allocation, operators may enable **Enter exact CAD amounts
  manually** and enter each PO's known product and shipping totals. Manual row amounts must
  reconcile exactly to the combined CAD payment.
- A manually entered product total determines that PO's product FX rate and CAD item unit costs;
  the combined payment retains its overall weighted FX rate for reconciliation.
- Preserve `purchase_order_items.vendor_unit_cost`, `purchase_orders.vendor_product_total`, and
  `purchase_orders.vendor_shipping_total`; convert only CAD header and line values.
- Allocation rounding must reconcile exactly to the entered CAD payment.
- Keep each PO's shipment method, dates, surcharge, and line membership unchanged.
- Block combined payment when any selected PO has received quantities, FIFO lots, a different
  currency, missing required vendor totals, or an existing combined payment.
- Persist a combined-payment header and per-PO allocation snapshots for reconciliation.

## Verification

- Backend feature tests cover products-only, products-plus-shipping, manual per-PO CAD amounts,
  exact reconciliation, original HKD preservation, and received-inventory rejection.
- Vue tests cover multi-PO selection, preview, exact manual allocation, and confirmation.
- Playwright covers selecting separate air/sea POs and previewing the allocation through the real
  API.

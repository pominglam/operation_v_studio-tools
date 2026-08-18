# Vendor order CSV (PM broker placement)

Use this when placing a **combined Dspiae + Stedi order** through the PM broker from draft PO line data.

This is **not** the same as the per-PO **Export order CSV** button on PO detail (`GET /api/v1/purchase-orders/{uuid}/draft-lines-export`), which exports **product cost only** (no shipping columns) for a single PO.

---

## When to use

- You have one or more **draft POs** (typically **Dspiae** and/or **Stedi**) and want a **single CSV** to send/enter at the broker.
- POs stay separate in the ERP; this export **merges rows only** for ordering convenience.

---

## Column spec (UTF-8 BOM CSV)

| Column | Required | Notes |
| --- | --- | --- |
| **Vendor** | yes | `Dspiae` or `Stedi` (canonical casing from PO header) |
| **Product Name** | yes | Line `product_name`, else linked product description |
| **SKU** | yes | ERP SKU |
| **Qty** | yes | `qty_ordered`; lines with qty `0` omitted |
| **Unit cost (CAD)** | yes | **Per unit**, product-only: line `unit_cost`, else HKD × FX |
| **Shipping cost (CAD)** | yes | **Line total** shipping: `Qty × unit_shipping`, where `unit_shipping = unit_cost × (PO.shipping_total ÷ PO.product_total)` |
| **Total cost (CAD)** | yes | **Line total** landed: `(Unit cost × Qty) + Shipping cost (CAD)` |
| **Unit cost (HKD)** | Dspiae/Stedi | **Per unit** product cost: line `vendor_unit_cost`, else `Unit cost (CAD) ÷ FX` |
| **Shipping cost (HKD)** | Dspiae/Stedi | **Line total** shipping (same allocation as CAD, converted or ratio-scaled) |
| **Total cost (HKD)** | Dspiae/Stedi | **Line total** landed: `(Unit cost × Qty) + Shipping cost (HKD)` |

**FX:** PO `fx_rate_to_cad` when set; otherwise latest prior PO with **HKD** vendor currency (same rule as draft-lines export).

**Header alignment:** When summed line `unit_cost × qty` differs from PO **`product_total`**, unit costs are scaled proportionally so product subtotals match the PO header before shipping is applied.

**Sort:** Vendor A→Z, then SKU A→Z.

**Totals check:** Sum of **Total cost (CAD)** column across rows should match each source PO’s **`product_total + shipping_total`** (small rounding drift possible).

---

## Example header row

```csv
Vendor,Product Name,SKU,Qty,Unit cost (CAD),Shipping cost (CAD),Total cost (CAD),Unit cost (HKD),Shipping cost (HKD),Total cost (HKD)
```

---

## One-off generation (until UI export exists)

From repo root, with `pricing-tool-php` container running:

```powershell
docker exec pricing-tool-php php tmp-export-merged-po.php
```

Output: `storage/app/private/exports/vendor-order-merged-YYYYMMDD.csv`

Edit PO UUIDs at the bottom of `tmp-export-merged-po.php` before running.

**Planned:** multi-PO **Export vendor order CSV** action (not implemented yet).

---

## Optional columns (consider for v2)

| Column | Why |
| --- | --- |
| **Barcode** | Receiving / label matching |
| **Shipment method** | Both POs are often `sea`; broker may need it on the order |
| **Landed unit (CAD/HKD)** | Per-unit product + shipping — only if broker form asks for unit landed separately |
| **FX rate used** | Audit when HKD is derived |
| **PM item name** | Only if broker form differs from ERP product name |

---

## Related

- PO detail **Export order CSV**: product cost only, single PO — `docs/features/screens/purchase-orders.md`
- PM broker import preview columns: `docs/features/screens/purchase-orders.md` (Dspiae/Stedi preview dialog)

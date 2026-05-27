<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductLatestCostCacheService;
use App\Services\Products\ProductTypeDerivationService;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderImportException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderImportService
{
    private const COL_SKU = 'SKU';

    private const COL_UNIT_COST = 'Unit cost';

    private const COL_QTY_ORDERED = 'Qty ordered';

    private const COL_QTY_SHIPPED = 'Qty shipped';

    private const COL_QTY_RECEIVED = 'Qty received';

    private const COL_DSPIAE_WHOLESALE_PRICE = 'Wholesale price';

    private const COL_DSPIAE_REQUIRED_QTY = 'Required Quantity / pcs (Carton Multiple)';

    private const COL_DSPIAE_PRODUCT_NAME = 'Product name';

    private const COL_DSPIAE_BARCODE = 'Barcode';

    private const COL_STEDI_WHOLESALE_PRICE_HKD = 'Wholesale price HKD';

    private const COL_STEDI_ORDER_QTY = 'Order qty';

    private const COL_STEDI_QTY = 'Qty';

    private const COL_STEDI_UNIT_PRICE_HKD = 'Unit Price (HK$)';

    private const COL_UNIT_PRICE_HKD = 'Unit Price (HKD)';

    private const COL_AMOUNT_HKD = 'Amount (HKD)';

    private const COL_STEDI_AMOUNT_HKD = 'Amount (HK$)';

    private const COL_PLAMOD_ORDER_ID = 'Order ID';

    private const COL_PLAMOD_PRODUCT_NAME = 'Product Name';

    private const COL_PLAMOD_QTY_ORDERED = 'Qty Ordered';

    private const COL_PLAMOD_QTY_FILLED = 'Qty Filled';

    private const COL_PLAMOD_UNIT_PRICE = 'Unit Price';

    private const COL_PLAMOD_ORDER_TYPE = 'Order Type';

    private const COL_JS_PDF_TEXT_HEADER = 'Item Description Quantity Price Per Unit Total';

    private const COL_AL_TITLE = 'Title';

    private const COL_AL_OPTION1_VALUE = 'Option1 Value';

    private const COL_AL_QUOTE = 'Quote';

    private const COL_AL_QTY = 'Qty';

    private const COL_AL_TOTAL = 'Total';

    private const COL_PM_ITEM = 'Item';

    private const COL_PM_UNIT_PRICE = 'Unit price';

    private const COL_PM_AMOUNT = 'Amount';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
        private readonly ProductTypeDerivationService $types,
        private readonly ProductLatestCostCacheService $latestCosts,
        private readonly PurchaseOrderXlsxReader $xlsxReader,
    ) {}

    /**
     * @param  array{
     *   vendor:string,
     *   supplier_order_id?:string|null,
     *   purchase_order_uuid?:string,
     *   import_mode?:string,
     *   ordered_date?:string|null,
     *   shipped_date?:string|null,
     *   estimated_arrival_date?:string|null,
     *   received_date?:string|null,
     *   fully_on_shelves_date?:string|null,
     *   shipping_total?:string|null,
     *   shipping_currency_mode?:string,
     *   product_total?:string|null,
     *   surcharge_total?:string|null,
     *   notes?:string|null,
     *   reset_receipt_before_reimport?:bool
     * } $meta
     * @return array{purchase_order_uuid:string, items:int, lots:int, shipping_per_unit:string|null}
     */
    public function import(UploadedFile $file, array $meta): array
    {
        $vendor = trim($meta['vendor']);
        if ($vendor === '') {
            throw new PurchaseOrderImportException('Vendor is required.');
        }

        $path = $file->getRealPath();
        if ($path === false) {
            throw new PurchaseOrderImportException('Uploaded file is not readable.');
        }

        $parsed = $this->parseUploadedFile($file, $vendor);
        $rows = $parsed['rows'];
        $preambleMeta = $parsed['preambleMeta'];

        if ($rows === []) {
            throw new PurchaseOrderImportException('No rows found in CSV.');
        }

        if (! array_key_exists('vendor_product_total', $preambleMeta) || trim((string) ($preambleMeta['vendor_product_total'] ?? '')) === '') {
            $derivedVendorTotal = $this->deriveVendorProductTotalFromRows($rows);
            if ($derivedVendorTotal !== null) {
                $preambleMeta['vendor_product_total'] = $derivedVendorTotal;
            }
        }

        $this->applyAllInFeesCadSplit($meta, $preambleMeta);

        $productsBySku = $this->resolveOrCreateProductsBySku($vendor, $rows);
        $importMode = array_key_exists('import_mode', $meta)
            ? strtolower(trim((string) $meta['import_mode']))
            : 'replace';
        $appendMode = $importMode === 'append';
        $lineFxRateToCad = $this->resolveImportLineFxRateToCad($meta, $preambleMeta);

        return DB::transaction(function () use ($meta, $vendor, $rows, $productsBySku, $preambleMeta, $importMode, $appendMode, $lineFxRateToCad): array {
            $po = $this->resolveOrCreatePurchaseOrder($meta, $vendor, $preambleMeta, $importMode);

            if (! $appendMode) {
                $po->fx_rate_to_cad = $this->deriveFxRateToCad(
                    $po->product_total !== null ? (string) $po->product_total : null,
                    $po->vendor_product_total !== null ? (string) $po->vendor_product_total : null,
                    (string) $po->vendor_currency_code,
                );
                $po->shipping_total = $this->normalizeShippingTotalToCad(
                    shippingTotal: $po->shipping_total !== null ? (string) $po->shipping_total : null,
                    mode: array_key_exists('shipping_currency_mode', $meta) ? (string) $meta['shipping_currency_mode'] : 'auto',
                    vendorCurrencyCode: (string) ($po->vendor_currency_code ?? 'CAD'),
                    fxRateToCad: $po->fx_rate_to_cad !== null ? (string) $po->fx_rate_to_cad : null,
                    productTotalCad: $po->product_total !== null ? (string) $po->product_total : null,
                );
                $this->purchaseOrders->save($po);
            }

            $fxRateForLines = $appendMode && $lineFxRateToCad !== null
                ? $lineFxRateToCad
                : ($po->fx_rate_to_cad !== null ? (string) $po->fx_rate_to_cad : null);

            $items = 0;
            $lots = 0;

            foreach ($rows as $r) {
                /** @var Product $product */
                $product = $productsBySku[(string) $r['sku']];

                $item = new PurchaseOrderItem;
                $item->purchase_order_id = $po->id;
                $item->product_id = (int) $product->id;
                $item->sku = (string) $product->sku;
                $item->vendor = (string) ($product->vendor ?? $vendor);
                $item->vendor_unit_cost = $po->vendor_currency_code !== 'CAD' ? $r['unit_cost'] : null;
                $item->unit_cost = $po->vendor_currency_code !== 'CAD'
                    ? ($item->vendor_unit_cost !== null && $fxRateForLines !== null ? $this->mulDecimalRounded((string) $item->vendor_unit_cost, $fxRateForLines, 4) : null)
                    : $r['unit_cost'];
                $item->qty_ordered = $r['qty_ordered'];
                $item->qty_shipped = $r['qty_shipped'];
                $item->qty_received = $r['qty_received'];
                $this->purchaseOrders->createItem($item);
                $items++;

                $qtyReceived = (int) ($item->qty_received ?? 0);
                if ($qtyReceived <= 0) {
                    continue;
                }

                $lot = new InventoryLot;
                $lot->product_id = (int) $product->id;
                $lot->purchase_order_item_id = $item->id;
                $lot->source_type = 'po';
                $lot->unit_cost = $item->unit_cost;
                $lot->shipping_per_unit = null;
                $lot->qty_received = $qtyReceived;
                $lot->qty_remaining = $qtyReceived;
                $lot->received_at = $this->resolveReceivedAt(
                    $po->received_date,
                    $po->shipped_date,
                    $po->ordered_date,
                );
                $this->inventory->createLot($lot);
                $lots++;
            }

            $shippingPerUnit = $this->recomputePurchaseOrderDerivedTotalsAndLots($po);

            if ($appendMode) {
                $combinedFx = $this->deriveFxRateToCad(
                    $po->product_total !== null ? (string) $po->product_total : null,
                    $po->vendor_product_total !== null ? (string) $po->vendor_product_total : null,
                    (string) $po->vendor_currency_code,
                );
                if ($combinedFx !== null) {
                    $po->fx_rate_to_cad = $combinedFx;
                    $this->purchaseOrders->save($po);
                }
            }

            $this->latestCosts->recomputeForSkus(array_values(array_unique(array_map(static fn (array $r): string => (string) $r['sku'], $rows))));

            return [
                'purchase_order_uuid' => (string) $po->uuid,
                'items' => $items,
                'lots' => $lots,
                'shipping_per_unit' => $shippingPerUnit,
            ];
        });
    }

    /**
     * @param  array{
     *   vendor:string,
     *   product_total?:string|null,
     *   shipping_total?:string|null,
     *   shipping_currency_mode?:string,
     *   product_total_includes_fees?:bool
     * }  $meta
     * @return array{
     *   format: string,
     *   vendor: string,
     *   vendor_currency_code: string,
     *   vendor_product_total_hkd: string|null,
     *   vendor_freight_total_hkd: string|null,
     *   product_total_cad: string|null,
     *   shipping_total_cad: string|null,
     *   total_paid_cad: string|null,
     *   product_total_includes_fees: bool,
     *   fx_rate_to_cad: string|null,
     *   lines: array<int, array<string, mixed>>,
     *   totals: array{qty:int, line_total_hkd:string|null, line_total_cad:string|null}
     * }
     */
    public function preview(UploadedFile $file, array $meta): array
    {
        $vendor = trim($meta['vendor']);
        if ($vendor === '') {
            throw new PurchaseOrderImportException('Vendor is required.');
        }

        $includesAllFees = $this->productTotalIncludesFees($meta);
        $totalPaidCad = array_key_exists('product_total', $meta) && $meta['product_total'] !== null
            ? trim((string) $meta['product_total'])
            : '';
        $totalPaidCad = $totalPaidCad !== '' ? $totalPaidCad : null;

        $parsed = $this->parseUploadedFile($file, $vendor);
        $rows = $parsed['rows'];
        $preambleMeta = $parsed['preambleMeta'];
        $format = $parsed['format'];

        if ($rows === []) {
            throw new PurchaseOrderImportException('No line items found in file.');
        }

        if (! array_key_exists('vendor_product_total', $preambleMeta) || trim((string) ($preambleMeta['vendor_product_total'] ?? '')) === '') {
            $derivedVendorTotal = $this->deriveVendorProductTotalFromRows($rows);
            if ($derivedVendorTotal !== null) {
                $preambleMeta['vendor_product_total'] = $derivedVendorTotal;
            }
        }

        $previewMeta = $meta;
        $this->applyAllInFeesCadSplit($previewMeta, $preambleMeta);

        $vendorCurrency = strtoupper(trim((string) ($preambleMeta['vendor_currency_code'] ?? 'CAD')));
        if ($vendorCurrency === '') {
            $vendorCurrency = 'CAD';
        }

        $productTotalCad = array_key_exists('product_total', $previewMeta) && $previewMeta['product_total'] !== null
            ? trim((string) $previewMeta['product_total'])
            : '';
        $productTotalCad = $productTotalCad !== '' ? $productTotalCad : null;

        $vendorProductTotal = isset($preambleMeta['vendor_product_total'])
            ? trim((string) $preambleMeta['vendor_product_total'])
            : '';
        $vendorProductTotal = $vendorProductTotal !== '' ? $vendorProductTotal : null;

        $vendorFreightTotal = isset($preambleMeta['vendor_freight_total'])
            ? trim((string) $preambleMeta['vendor_freight_total'])
            : '';
        $vendorFreightTotal = $vendorFreightTotal !== '' ? $vendorFreightTotal : null;

        $fxRate = $this->deriveFxRateToCad($productTotalCad, $vendorProductTotal, $vendorCurrency);

        $shippingMode = array_key_exists('shipping_currency_mode', $meta)
            ? (string) $meta['shipping_currency_mode']
            : 'auto';
        $shippingRaw = array_key_exists('shipping_total', $previewMeta) && $previewMeta['shipping_total'] !== null
            ? trim((string) $previewMeta['shipping_total'])
            : '';
        $shippingCad = $this->normalizeShippingTotalToCad(
            shippingTotal: $shippingRaw !== '' ? $shippingRaw : null,
            mode: $includesAllFees ? 'cad' : $shippingMode,
            vendorCurrencyCode: $vendorCurrency,
            fxRateToCad: $fxRate,
            productTotalCad: $productTotalCad,
        );

        $lines = [];
        $totalQty = 0;
        $sumHkd = 0.0;
        $sumCad = 0.0;
        $hasHkd = false;
        $hasCad = false;

        foreach ($rows as $r) {
            $qty = (int) ($r['qty_ordered'] ?? 0);
            $unitHkd = is_string($r['unit_cost'] ?? null) ? trim((string) $r['unit_cost']) : '';
            $lineHkdRaw = array_key_exists('vendor_line_total', $r) ? $r['vendor_line_total'] : null;

            $unitCad = null;
            $lineCad = null;
            if ($fxRate !== null && $unitHkd !== '' && is_numeric($unitHkd)) {
                $unitCad = $this->mulDecimalRounded($unitHkd, $fxRate, 2);
            }
            if ($fxRate !== null && $lineHkdRaw !== null && is_string($lineHkdRaw) && trim($lineHkdRaw) !== '' && is_numeric($lineHkdRaw)) {
                $lineCad = $this->mulDecimalRounded(trim($lineHkdRaw), $fxRate, 2);
            } elseif ($fxRate !== null && $unitHkd !== '' && is_numeric($unitHkd) && $qty > 0) {
                $lineCad = $this->mulDecimalRounded(
                    $this->mulDecimalRounded($unitHkd, (string) $qty, 2),
                    $fxRate,
                    2,
                );
            }

            if ($lineHkdRaw !== null && is_string($lineHkdRaw) && trim($lineHkdRaw) !== '' && is_numeric($lineHkdRaw)) {
                $lineHkdFormatted = $this->formatMoney2(trim($lineHkdRaw));
            } elseif ($unitHkd !== '' && is_numeric($unitHkd) && $qty > 0) {
                $lineHkdFormatted = $this->mulDecimalRounded($unitHkd, (string) $qty, 2);
            } else {
                $lineHkdFormatted = null;
            }

            if ($lineHkdFormatted !== null) {
                $sumHkd += (float) $lineHkdFormatted;
                $hasHkd = true;
            }
            if ($lineCad !== null && is_numeric($lineCad)) {
                $sumCad += (float) $lineCad;
                $hasCad = true;
            }
            $totalQty += $qty;

            $lines[] = [
                'item' => $r['product_name'] ?? null,
                'sku' => (string) $r['sku'],
                'qty' => $qty,
                'unit_price_hkd' => $unitHkd !== '' ? $this->formatMoney2($unitHkd) : null,
                'line_total_hkd' => $lineHkdFormatted,
                'unit_price_cad' => $unitCad,
                'line_total_cad' => $lineCad,
            ];
        }

        return [
            'format' => $format,
            'vendor' => $vendor,
            'vendor_currency_code' => $vendorCurrency,
            'vendor_product_total_hkd' => $this->formatMoney2($vendorProductTotal),
            'vendor_freight_total_hkd' => $this->formatMoney2($vendorFreightTotal),
            'product_total_cad' => $this->formatMoney2($productTotalCad),
            'shipping_total_cad' => $this->formatMoney2($shippingCad),
            'total_paid_cad' => $includesAllFees ? $this->formatMoney2($totalPaidCad) : null,
            'product_total_includes_fees' => $includesAllFees,
            'fx_rate_to_cad' => $fxRate,
            'lines' => $lines,
            'totals' => [
                'qty' => $totalQty,
                'line_total_hkd' => $hasHkd ? $this->formatMoney2(number_format($sumHkd, 4, '.', '')) : null,
                'line_total_cad' => $hasCad ? $this->formatMoney2(number_format($sumCad, 4, '.', '')) : null,
            ],
        ];
    }

    /**
     * @param  array{product_total?:string|null, shipping_total?:string|null, product_total_includes_fees?:bool}  $meta
     * @param  array{vendor_product_total?:string|null, vendor_currency_code?:string}  $preambleMeta
     */
    private function resolveImportLineFxRateToCad(array $meta, array $preambleMeta): ?string
    {
        $productCad = array_key_exists('product_total', $meta) && $meta['product_total'] !== null
            ? trim((string) $meta['product_total'])
            : '';
        $vendorHkd = isset($preambleMeta['vendor_product_total'])
            ? trim((string) $preambleMeta['vendor_product_total'])
            : '';
        if ($productCad === '' || $vendorHkd === '') {
            return null;
        }

        $currency = strtoupper(trim((string) ($preambleMeta['vendor_currency_code'] ?? 'HKD')));
        if ($currency === '') {
            $currency = 'HKD';
        }

        return $this->deriveFxRateToCad($productCad, $vendorHkd, $currency);
    }

    private function formatMoney2(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }

    private function normalizeShippingTotalToCad(
        ?string $shippingTotal,
        string $mode,
        string $vendorCurrencyCode,
        ?string $fxRateToCad,
        ?string $productTotalCad,
    ): ?string {
        if ($shippingTotal === null || trim($shippingTotal) === '') {
            return null;
        }

        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['auto', 'cad', 'vendor'], true)) {
            $mode = 'auto';
        }

        $vendorCurrencyCode = strtoupper(trim($vendorCurrencyCode));
        if ($vendorCurrencyCode === '' || $vendorCurrencyCode === 'CAD') {
            return $shippingTotal;
        }

        if ($mode === 'cad') {
            return $shippingTotal;
        }

        if ($mode === 'vendor') {
            if ($fxRateToCad === null || trim($fxRateToCad) === '' || ! is_numeric($fxRateToCad)) {
                throw new PurchaseOrderImportException('Shipping currency set to vendor currency, but FX rate to CAD is unavailable. Provide Product total (CAD) so FX can be derived, or import shipping as CAD.');
            }

            return $this->mulDecimalRounded($shippingTotal, $fxRateToCad, 2);
        }

        if ($this->shouldTreatAutoShippingAsVendorCurrency($shippingTotal, $fxRateToCad, $productTotalCad)) {
            return $this->mulDecimalRounded($shippingTotal, (string) $fxRateToCad, 2);
        }

        return $shippingTotal;
    }

    private function shouldTreatAutoShippingAsVendorCurrency(
        string $shippingTotal,
        ?string $fxRateToCad,
        ?string $productTotalCad,
    ): bool {
        if ($fxRateToCad === null || trim($fxRateToCad) === '' || ! is_numeric($fxRateToCad)) {
            return false;
        }
        if ($productTotalCad === null || trim($productTotalCad) === '' || ! is_numeric($productTotalCad)) {
            return false;
        }
        if (! is_numeric($shippingTotal)) {
            return false;
        }

        $shipping = (float) $shippingTotal;
        $productCad = (float) $productTotalCad;
        $shippingAsVendorToCad = $shipping * (float) $fxRateToCad;

        if ($productCad <= 0 || $shipping <= 0) {
            return false;
        }

        // High-confidence conversion signal:
        // - as-entered shipping is bigger than full product CAD total (unlikely),
        // - but converted shipping lands below product CAD total (plausible).
        return $shipping > $productCad && $shippingAsVendorToCad <= $productCad;
    }

    /**
     * @param  array{
     *   vendor:string,
     *   supplier_order_id?:string|null,
     *   purchase_order_uuid?:string,
     *   import_mode?:string,
     *   ordered_date?:string|null,
     *   shipped_date?:string|null,
     *   estimated_arrival_date?:string|null,
     *   received_date?:string|null,
     *   fully_on_shelves_date?:string|null,
     *   shipping_total?:string|null,
     *   product_total?:string|null,
     *   surcharge_total?:string|null,
     *   notes?:string|null,
     *   reset_receipt_before_reimport?:bool
     * } $meta
     * @param  array{vendor_currency_code?:string,vendor_product_total?:string|null}  $preambleMeta
     */
    private function resolveOrCreatePurchaseOrder(array $meta, string $vendor, array $preambleMeta, string $importMode = 'replace'): PurchaseOrder
    {
        $uuid = array_key_exists('purchase_order_uuid', $meta) ? trim((string) $meta['purchase_order_uuid']) : '';
        $appendMode = $importMode === 'append';

        if ($uuid !== '') {
            $po = $this->purchaseOrders->findByUuidOrFail($uuid);
            if (! $po->relationLoaded('items')) {
                $po->load('items');
            }

            if (! $appendMode) {
                /** @var array<int, int> $itemIds */
                $itemIds = $po->items->pluck('id')->map(static fn ($v): int => (int) $v)->values()->all();

                $resetReceipt = array_key_exists('reset_receipt_before_reimport', $meta)
                    && filter_var($meta['reset_receipt_before_reimport'], FILTER_VALIDATE_BOOLEAN);

                if ($resetReceipt && $itemIds !== []) {
                    $this->inventory->deleteMovementsAndLotsForPurchaseOrderItems($itemIds);
                    foreach ($po->items as $line) {
                        $line->qty_received = null;
                        $this->purchaseOrders->saveItem($line);
                    }
                    $po->load('items');
                    $itemIds = $po->items->pluck('id')->map(static fn ($v): int => (int) $v)->values()->all();
                }

                $hasReceived = $po->items->contains(static fn ($it): bool => ((int) ($it->qty_received ?? 0)) > 0);
                $lots = $this->inventory->countLotsForPurchaseOrderItems($itemIds);
                if ($hasReceived || $lots > 0) {
                    throw new PurchaseOrderImportException(
                        'Re-import blocked: this PO still has received quantities and/or inventory lots. Enable Clear PO receipt data first on re-import, use Import more to append lines, or create a new PO.',
                        [
                            ['kind' => 'reimport_not_allowed', 'purchase_order_uuid' => (string) $po->uuid],
                        ],
                    );
                }

                // Replace all items for a clean re-import.
                $this->purchaseOrders->deleteItemsForPurchaseOrder((int) $po->id);
            }

            // Update header fields (only when present in request).
            $po->vendor = $vendor;
            if (array_key_exists('supplier_order_id', $meta)) {
                $nextSupplierOrderId = trim((string) ($meta['supplier_order_id'] ?? ''));
                $po->supplier_order_id = $nextSupplierOrderId !== '' ? $nextSupplierOrderId : null;
            }
            if (! $appendMode) {
                $po->vendor_currency_code = $preambleMeta['vendor_currency_code'] ?? $po->vendor_currency_code ?? 'CAD';
                $po->vendor_product_total = $preambleMeta['vendor_product_total'] ?? $po->vendor_product_total;
            } else {
                $this->mergeAppendHeaderTotals($po, $meta, $preambleMeta);
            }
            if (array_key_exists('ordered_date', $meta)) {
                $po->ordered_date = $meta['ordered_date'];
            }
            if (array_key_exists('shipped_date', $meta)) {
                $po->shipped_date = $meta['shipped_date'];
            }
            if (array_key_exists('estimated_arrival_date', $meta)) {
                $po->estimated_arrival_date = $meta['estimated_arrival_date'];
            }
            if (array_key_exists('received_date', $meta)) {
                $po->received_date = $meta['received_date'];
            }
            if (array_key_exists('fully_on_shelves_date', $meta)) {
                $po->fully_on_shelves_date = $meta['fully_on_shelves_date'];
            }
            if (! $appendMode) {
                if (array_key_exists('shipping_total', $meta)) {
                    $po->shipping_total = $meta['shipping_total'];
                }
                if (array_key_exists('product_total', $meta)) {
                    $po->product_total = $meta['product_total'];
                }
            }
            if (array_key_exists('surcharge_total', $meta) && ! $appendMode) {
                $po->surcharge_total = $meta['surcharge_total'];
            }
            if (array_key_exists('notes', $meta)) {
                $po->notes = $meta['notes'];
            }

            return $po;
        }

        $po = new PurchaseOrder;
        $po->vendor = $vendor;
        $supplierOrderId = array_key_exists('supplier_order_id', $meta)
            ? trim((string) ($meta['supplier_order_id'] ?? ''))
            : '';
        $po->supplier_order_id = $supplierOrderId !== '' ? $supplierOrderId : null;
        $po->vendor_currency_code = $preambleMeta['vendor_currency_code'] ?? 'CAD';
        $po->vendor_product_total = $preambleMeta['vendor_product_total'] ?? null;
        $po->ordered_date = array_key_exists('ordered_date', $meta) ? $meta['ordered_date'] : null;
        $po->shipped_date = array_key_exists('shipped_date', $meta) ? $meta['shipped_date'] : null;
        $po->estimated_arrival_date = array_key_exists('estimated_arrival_date', $meta) ? $meta['estimated_arrival_date'] : null;
        $po->received_date = array_key_exists('received_date', $meta) ? $meta['received_date'] : null;
        $po->fully_on_shelves_date = array_key_exists('fully_on_shelves_date', $meta) ? $meta['fully_on_shelves_date'] : null;
        $po->shipping_total = array_key_exists('shipping_total', $meta) ? $meta['shipping_total'] : null;
        $po->product_total = array_key_exists('product_total', $meta) ? $meta['product_total'] : null;
        $po->surcharge_total = array_key_exists('surcharge_total', $meta) ? $meta['surcharge_total'] : null;
        $po->notes = array_key_exists('notes', $meta) ? $meta['notes'] : null;
        $this->purchaseOrders->create($po);

        return $po;
    }

    private function resolveReceivedAt(string|DateTimeInterface|null $receivedDate, string|DateTimeInterface|null $shippedDate, string|DateTimeInterface|null $orderedDate): DateTimeInterface
    {
        $candidate = $receivedDate ?? $shippedDate ?? $orderedDate;
        if ($candidate instanceof DateTimeInterface) {
            return CarbonImmutable::instance($candidate)->startOfDay();
        }

        if ($candidate === null || trim($candidate) === '') {
            return now();
        }

        return CarbonImmutable::parse($candidate)->startOfDay();
    }

    private function recomputePurchaseOrderDerivedTotalsAndLots(PurchaseOrder $po): ?string
    {
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

        $totalReceived = 0;
        $productTotalCents = 0;
        $hasCadUnitCosts = false;
        foreach ($items as $item) {
            $qtyReceived = (int) ($item->qty_received ?? 0);
            if ($qtyReceived > 0) {
                $totalReceived += $qtyReceived;
            }

            $qtyForProductTotal = $qtyReceived > 0 ? $qtyReceived : (int) ($item->qty_ordered ?? 0);
            if ($qtyForProductTotal <= 0) {
                continue;
            }

            $unitCostCents = $item->unit_cost !== null ? $this->moneyToCentsOrNull((string) $item->unit_cost) : null;
            if ($unitCostCents === null) {
                continue;
            }

            $hasCadUnitCosts = true;
            $productTotalCents += ($unitCostCents * $qtyForProductTotal);
        }

        $shippingPerUnit = null;
        $shippingTotal = $po->shipping_total !== null ? trim((string) $po->shipping_total) : null;
        if ($shippingTotal !== null && $shippingTotal !== '' && $totalReceived > 0) {
            $shippingPerUnit = $this->divideDecimal($shippingTotal, $totalReceived, 6);
        }

        // For foreign-currency imports without a known FX rate yet, unit_cost (CAD)
        // can be null for all lines. In that case, preserve user-provided product_total
        // instead of forcing it to 0.00.
        if ($hasCadUnitCosts) {
            $po->product_total = $this->centsToMoney($productTotalCents);
        }
        $this->purchaseOrders->save($po);

        $itemIds = $items->pluck('id')->all();
        if ($itemIds !== []) {
            InventoryLot::query()
                ->whereIn('purchase_order_item_id', $itemIds)
                ->where('source_type', '=', 'po')
                ->update([
                    'shipping_per_unit' => $shippingPerUnit,
                    'received_at' => $this->resolveReceivedAt(
                        $po->received_date,
                        $po->shipped_date,
                        $po->ordered_date,
                    ),
                    'updated_at' => now(),
                ]);
        }

        return $shippingPerUnit;
    }

    private function moneyToCentsOrNull(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || $clean === '-' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        $negative = str_starts_with($clean, '-');
        $raw = $negative ? substr($clean, 1) : $clean;
        $parts = explode('.', $raw, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = str_pad((string) ($parts[1] ?? ''), 3, '0');
        $cents2 = substr($fraction, 0, 2);
        $third = (int) substr($fraction, 2, 1);

        $cents = ((int) $whole) * 100 + ((int) $cents2);
        if ($third >= 5) {
            $cents += 1;
        }

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $dollars = intdiv($abs, 100);
        $remainder = $abs % 100;

        return sprintf('%s%d.%02d', $negative ? '-' : '', $dollars, $remainder);
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $key = trim($this->sanitizeUtf8((string) $name));
            if ($i === 0) {
                $key = ltrim($key, "\xEF\xBB\xBF");
            }
            if ($key === '') {
                continue;
            }
            $map[$key] = $i;
            $this->addHeaderAliases($map, $key, $i);
        }

        return $map;
    }

    /**
     * Add case/format-insensitive aliases for known column names.
     *
     * @param  array<string, int>  $map
     */
    private function addHeaderAliases(array &$map, string $key, int $index): void
    {
        $normalized = strtolower(trim($key));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = str_replace(['（', '）'], ['(', ')'], $normalized);
        $compactParens = preg_replace('/\s*\(\s*/u', '(', $normalized) ?? $normalized;
        $compactParens = preg_replace('/\s*\)\s*/u', ')', $compactParens) ?? $compactParens;

        $aliases = [
            'sku' => self::COL_SKU,
            'qty' => self::COL_STEDI_QTY,
            'qty*' => self::COL_AL_QTY,
            'order qty' => self::COL_STEDI_ORDER_QTY,
            'wholesale price hkd' => self::COL_STEDI_WHOLESALE_PRICE_HKD,
            'quote' => self::COL_AL_QUOTE,
            'title' => self::COL_AL_TITLE,
            'option1 value' => self::COL_AL_OPTION1_VALUE,
            'unit price(hk$)' => self::COL_STEDI_UNIT_PRICE_HKD,
            'amount(hk$)' => self::COL_STEDI_AMOUNT_HKD,
            'unit price(hkd)' => self::COL_UNIT_PRICE_HKD,
            'amount(hkd)' => self::COL_AMOUNT_HKD,
            'total' => self::COL_AL_TOTAL,
            'item' => self::COL_PM_ITEM,
            'unit price' => self::COL_PM_UNIT_PRICE,
            'amount' => self::COL_PM_AMOUNT,
        ];

        $aliasKey = $aliases[$compactParens] ?? null;
        if ($aliasKey !== null && ! array_key_exists($aliasKey, $map)) {
            $map[$aliasKey] = $index;
        }

        // Treat HKD/HK$ simple Stedi variants as equivalent so either parser path can read amounts.
        if ($compactParens === 'unit price(hkd)' && ! array_key_exists(self::COL_STEDI_UNIT_PRICE_HKD, $map)) {
            $map[self::COL_STEDI_UNIT_PRICE_HKD] = $index;
        }
        if ($compactParens === 'amount(hkd)' && ! array_key_exists(self::COL_STEDI_AMOUNT_HKD, $map)) {
            $map[self::COL_STEDI_AMOUNT_HKD] = $index;
        }
    }

    /**
     * @param  array<string, int>  $map
     */
    private function detectFormat(array $map): string
    {
        if ($this->looksLikeJsPdfHeader($map)) {
            return 'js_pdf_text';
        }

        $standardCols = [self::COL_SKU, self::COL_UNIT_COST, self::COL_QTY_ORDERED, self::COL_QTY_SHIPPED, self::COL_QTY_RECEIVED];
        $isStandard = true;
        foreach ($standardCols as $col) {
            if (! array_key_exists($col, $map)) {
                $isStandard = false;
                break;
            }
        }
        if ($isStandard) {
            return 'standard';
        }

        $dspiaeCols = [
            self::COL_SKU,
            self::COL_DSPIAE_PRODUCT_NAME,
            self::COL_DSPIAE_BARCODE,
            self::COL_DSPIAE_WHOLESALE_PRICE,
            self::COL_DSPIAE_REQUIRED_QTY,
        ];
        $isDspiae = true;
        foreach ($dspiaeCols as $col) {
            if (! array_key_exists($col, $map)) {
                $isDspiae = false;
                break;
            }
        }
        if ($isDspiae) {
            return 'dspiae';
        }

        $stediCols = [
            self::COL_SKU,
            self::COL_STEDI_WHOLESALE_PRICE_HKD,
            self::COL_STEDI_ORDER_QTY,
        ];
        $isStedi = true;
        foreach ($stediCols as $col) {
            if (! array_key_exists($col, $map)) {
                $isStedi = false;
                break;
            }
        }
        if ($isStedi) {
            return 'stedi_tools';
        }

        $stediSimpleCols = [
            self::COL_SKU,
            self::COL_STEDI_QTY,
            self::COL_STEDI_UNIT_PRICE_HKD,
        ];
        $isStediSimple = true;
        foreach ($stediSimpleCols as $col) {
            if (! array_key_exists($col, $map)) {
                $isStediSimple = false;
                break;
            }
        }
        if ($isStediSimple) {
            return 'stedi_simple';
        }

        $simpleHkdCols = [
            self::COL_SKU,
            self::COL_STEDI_QTY,
            self::COL_UNIT_PRICE_HKD,
        ];
        $isSimpleHkd = true;
        foreach ($simpleHkdCols as $col) {
            if (! array_key_exists($col, $map)) {
                $isSimpleHkd = false;
                break;
            }
        }
        if ($isSimpleHkd) {
            return 'simple_hkd';
        }

        $alCols = [
            self::COL_AL_TITLE,
            self::COL_AL_OPTION1_VALUE,
            self::COL_SKU,
            self::COL_AL_QUOTE,
            self::COL_AL_QTY,
            self::COL_AL_TOTAL,
        ];
        $isAl = true;
        foreach ($alCols as $col) {
            if (! array_key_exists($col, $map)) {
                $isAl = false;
                break;
            }
        }
        if ($isAl) {
            return 'al';
        }

        $plamodCols = [
            self::COL_PLAMOD_ORDER_ID,
            self::COL_SKU,
            self::COL_DSPIAE_BARCODE,
            self::COL_PLAMOD_PRODUCT_NAME,
            self::COL_PLAMOD_QTY_ORDERED,
            self::COL_PLAMOD_QTY_FILLED,
            self::COL_PLAMOD_UNIT_PRICE,
        ];
        foreach ($plamodCols as $col) {
            if (! array_key_exists($col, $map)) {
                throw new PurchaseOrderImportException("Missing required column: {$col}");
            }
        }

        return 'plamod_order_details';
    }

    /**
     * @param  array<string, int>  $map
     */
    private function looksLikeJsPdfHeader(array $map): bool
    {
        foreach (array_keys($map) as $k) {
            $line = strtolower(trim((string) $k));
            if ($line === '') {
                continue;
            }
            if (str_contains($line, strtolower(self::COL_JS_PDF_TEXT_HEADER))) {
                return true;
            }
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
            if ($line === 'item description quantity price per unit total') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   rows: array<int, array{
     *     row:int,
     *     sku:string,
     *     unit_cost:string|null,
     *     qty_ordered:int|null,
     *     qty_shipped:int|null,
     *     qty_received:int|null,
     *     product_name:string|null,
     *     barcode:string|null,
     *     vendor_line_total?:string|null
     *   }>,
     *   preambleMeta: array{vendor_currency_code?:string,vendor_product_total?:string|null,format_vendor_currency_code?:string|null},
     *   format: string
     * }
     */
    private function parseUploadedFile(UploadedFile $file, string $vendor): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new PurchaseOrderImportException('Uploaded file is not readable.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $isXlsx = $extension === 'xlsx'
            || str_contains($mime, 'spreadsheetml')
            || str_contains($mime, 'excel');

        $sheetRows = $isXlsx ? $this->xlsxReader->read($path) : $this->readCsvRows($path);
        $encodingIssues = [];
        [$headerRowIndex, $preambleMeta, $map, $format] = $this->scanSheetForHeader($sheetRows, $vendor, $encodingIssues);
        $preambleMeta = $this->mergeFormatCurrencyFallback($preambleMeta, $format);

        $rows = $format === 'js_pdf_text'
            ? $this->parseJsPdfTextRowsFromSheet($sheetRows, $headerRowIndex, $encodingIssues)
            : $this->parseSheetDataRows($sheetRows, $headerRowIndex, $map, $format, $encodingIssues);

        if ($format === 'pm_invoice') {
            $freightHkd = $this->extractPmInvoiceFreightHkdFromSheetRows(
                $sheetRows,
                $headerRowIndex,
                $map,
                $encodingIssues,
            );
            if ($freightHkd !== null) {
                $preambleMeta['vendor_freight_total'] = $freightHkd;
            }
        }

        return [
            'rows' => $rows,
            'preambleMeta' => $preambleMeta,
            'format' => $format,
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsvRows(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new PurchaseOrderImportException('Uploaded file is not readable.');
        }

        /** @var array<int, array<int, string>> $rows */
        $rows = [];
        try {
            while (($data = fgetcsv($fh)) !== false) {
                $rows[] = $data;
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string>>  $sheetRows
     * @param  array<int, array{kind:string,row:int,col:int}>  $encodingIssues
     * @return array{
     *   0:int,
     *   1:array{vendor_currency_code?:string,vendor_product_total?:string|null,format_vendor_currency_code?:string|null},
     *   2:array<string, int>,
     *   3:string
     * }
     */
    private function scanSheetForHeader(array $sheetRows, string $vendor, array &$encodingIssues): array
    {
        $meta = [];

        foreach ($sheetRows as $i => $rawRow) {
            $rowNumber = $i + 1;
            $row = $this->sanitizeCsvRow($rawRow, $rowNumber, $encodingIssues);
            if ($this->isBlankRow($row)) {
                continue;
            }

            if ($this->looksLikeJsHeaderRow($row)) {
                return [$i, $meta, $this->headerMap($row), 'js_pdf_text'];
            }

            $maybeHeader = $this->headerMap($row);
            $looksHeader = array_key_exists(self::COL_SKU, $maybeHeader)
                && (
                    array_key_exists(self::COL_UNIT_COST, $maybeHeader)
                    || array_key_exists(self::COL_DSPIAE_WHOLESALE_PRICE, $maybeHeader)
                    || array_key_exists(self::COL_STEDI_WHOLESALE_PRICE_HKD, $maybeHeader)
                    || array_key_exists(self::COL_STEDI_UNIT_PRICE_HKD, $maybeHeader)
                    || array_key_exists(self::COL_UNIT_PRICE_HKD, $maybeHeader)
                    || array_key_exists(self::COL_AL_QUOTE, $maybeHeader)
                    || array_key_exists(self::COL_PLAMOD_UNIT_PRICE, $maybeHeader)
                    || $this->looksLikePmInvoiceHeader($maybeHeader)
                );

            if ($looksHeader) {
                $map = $maybeHeader;
                $format = $this->resolveFormat($map, $vendor);

                return [$i, $meta, $map, $format];
            }

            $parsed = $this->parseVendorTotalFromPreambleRow($row);
            if ($parsed !== null) {
                $meta = array_merge($meta, $parsed);
            }

            if ($rowNumber > 50) {
                break;
            }
        }

        throw new PurchaseOrderImportException('Could not find a valid CSV header row.');
    }

    /**
     * @param  array<string, int>  $map
     */
    private function resolveFormat(array $map, string $vendor): string
    {
        if ($this->isPmBrokerVendor($vendor) && $this->looksLikePmInvoiceHeader($map)) {
            return 'pm_invoice';
        }

        return $this->detectFormat($map);
    }

    private function isPmBrokerVendor(string $vendor): bool
    {
        $normalized = strtolower(trim($vendor));

        return in_array($normalized, ['dspiae', 'stedi'], true);
    }

    /**
     * @param  array<string, int>  $map
     */
    private function looksLikePmInvoiceHeader(array $map): bool
    {
        foreach ([self::COL_SKU, self::COL_PM_ITEM, self::COL_STEDI_QTY, self::COL_PM_UNIT_PRICE, self::COL_PM_AMOUNT] as $col) {
            if (! array_key_exists($col, $map)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<int, string>>  $sheetRows
     * @param  array<string, int>  $map
     * @param  array<int, array{kind:string,row:int,col:int}>  $encodingIssues
     * @return array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>
     */
    private function parseSheetDataRows(array $sheetRows, int $headerRowIndex, array $map, string $format, array &$encodingIssues): array
    {
        $rows = [];

        for ($i = $headerRowIndex + 1; $i < count($sheetRows); $i++) {
            $rowNumber = $i + 1;
            $data = $this->sanitizeCsvRow($sheetRows[$i], $rowNumber, $encodingIssues);
            if ($this->isBlankRow($data)) {
                continue;
            }

            if ($format === 'plamod_order_details') {
                $marker = strtoupper(trim((string) ($data[0] ?? '')));
                if (in_array($marker, ['SUMMARY', 'TOTALS', 'STATEMENT'], true)) {
                    break;
                }
            }

            $parsed = $this->parseImportDataRow($data, $map, $format, $rowNumber);
            if ($parsed === null) {
                continue;
            }
            $rows[] = $parsed;
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $data
     * @param  array<string, int>  $map
     * @return array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }|null
     */
    private function parseImportDataRow(array $data, array $map, string $format, int $rowNumber): ?array
    {
        if ($format === 'standard') {
            $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            if ($sku === '') {
                throw new PurchaseOrderImportException('Missing SKU value.', [
                    ['row' => $rowNumber, 'kind' => 'missing_sku'],
                ]);
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_UNIT_COST]),
                'qty_ordered' => $this->nullableIntAt($data, $map[self::COL_QTY_ORDERED]),
                'qty_shipped' => $this->nullableIntAt($data, $map[self::COL_QTY_SHIPPED]),
                'qty_received' => $this->nullableIntAt($data, $map[self::COL_QTY_RECEIVED]),
                'product_name' => null,
                'barcode' => null,
                'vendor_line_total' => null,
            ];
        }

        if ($format === 'pm_invoice') {
            $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            if ($sku === '') {
                return null;
            }

            $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_STEDI_QTY] ?? -1);
            if (($qtyOrdered ?? 0) <= 0) {
                return null;
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'product_name' => $this->nullableStringAt($data, $map[self::COL_PM_ITEM] ?? -1),
                'barcode' => null,
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_PM_UNIT_PRICE] ?? -1),
                'qty_ordered' => $qtyOrdered,
                'qty_shipped' => null,
                'qty_received' => null,
                'vendor_line_total' => $this->nullableDecimalAt($data, $map[self::COL_PM_AMOUNT] ?? -1),
            ];
        }

        if ($format === 'dspiae') {
            $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            if ($sku === '') {
                return null;
            }

            $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_DSPIAE_REQUIRED_QTY]);
            if (($qtyOrdered ?? 0) <= 0) {
                return null;
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'product_name' => $this->nullableStringAt($data, $map[self::COL_DSPIAE_PRODUCT_NAME] ?? -1),
                'barcode' => $this->nullableStringAt($data, $map[self::COL_DSPIAE_BARCODE] ?? -1),
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_DSPIAE_WHOLESALE_PRICE]),
                'qty_ordered' => $qtyOrdered,
                'qty_shipped' => null,
                'qty_received' => null,
                'vendor_line_total' => null,
            ];
        }

        if ($format === 'stedi_tools') {
            $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            if ($sku === '') {
                return null;
            }

            $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_STEDI_ORDER_QTY] ?? -1);
            if (($qtyOrdered ?? 0) <= 0) {
                return null;
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_STEDI_WHOLESALE_PRICE_HKD] ?? -1),
                'qty_ordered' => $qtyOrdered,
                'qty_shipped' => null,
                'qty_received' => null,
                'product_name' => null,
                'barcode' => null,
                'vendor_line_total' => null,
            ];
        }

        if ($format === 'stedi_simple') {
            $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            if ($sku === '') {
                return null;
            }

            $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_STEDI_QTY] ?? -1);
            if (($qtyOrdered ?? 0) <= 0) {
                return null;
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_STEDI_UNIT_PRICE_HKD] ?? -1),
                'qty_ordered' => $qtyOrdered,
                'qty_shipped' => null,
                'qty_received' => null,
                'product_name' => null,
                'barcode' => null,
                'vendor_line_total' => $this->nullableDecimalAt($data, $map[self::COL_STEDI_AMOUNT_HKD] ?? -1),
            ];
        }

        if ($format === 'simple_hkd') {
            $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            if ($sku === '') {
                return null;
            }

            $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_STEDI_QTY] ?? -1);
            if (($qtyOrdered ?? 0) <= 0) {
                return null;
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_UNIT_PRICE_HKD] ?? -1),
                'qty_ordered' => $qtyOrdered,
                'qty_shipped' => null,
                'qty_received' => null,
                'product_name' => null,
                'barcode' => null,
                'vendor_line_total' => $this->nullableDecimalAt($data, $map[self::COL_AMOUNT_HKD] ?? -1),
            ];
        }

        if ($format === 'al') {
            $rawSku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
            $sku = $this->normalizeAlSku($rawSku);
            if ($sku === '') {
                throw new PurchaseOrderImportException('Missing SKU value.', [
                    ['row' => $rowNumber, 'kind' => 'missing_sku'],
                ]);
            }

            $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_AL_QTY] ?? -1);
            if (($qtyOrdered ?? 0) <= 0) {
                return null;
            }

            return [
                'row' => $rowNumber,
                'sku' => $sku,
                'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_AL_QUOTE] ?? -1),
                'qty_ordered' => $qtyOrdered,
                'qty_shipped' => null,
                'qty_received' => null,
                'product_name' => $this->nullableStringAt($data, $map[self::COL_AL_TITLE] ?? -1),
                'barcode' => null,
                'vendor_line_total' => $this->nullableDecimalAt($data, $map[self::COL_AL_TOTAL] ?? -1),
            ];
        }

        $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
        if ($sku === '') {
            return null;
        }

        $qtyFilled = $this->nullableIntAt($data, $map[self::COL_PLAMOD_QTY_FILLED]);

        return [
            'row' => $rowNumber,
            'sku' => $sku,
            'product_name' => $this->nullableStringAt($data, $map[self::COL_PLAMOD_PRODUCT_NAME] ?? -1),
            'barcode' => $this->nullableStringAt($data, $map[self::COL_DSPIAE_BARCODE] ?? -1),
            'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_PLAMOD_UNIT_PRICE]),
            'qty_ordered' => $this->nullableIntAt($data, $map[self::COL_PLAMOD_QTY_ORDERED]),
            'qty_shipped' => $qtyFilled,
            'qty_received' => $qtyFilled,
            'vendor_line_total' => null,
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $sheetRows
     * @param  array<int, array{kind:string,row:int,col:int}>  $encodingIssues
     * @return array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>
     */
    private function parseJsPdfTextRowsFromSheet(array $sheetRows, int $headerRowIndex, array &$encodingIssues): array
    {
        $rows = [];
        $descBuffer = [];

        for ($i = $headerRowIndex + 1; $i < count($sheetRows); $i++) {
            $rowNumber = $i + 1;
            $data = $this->sanitizeCsvRow($sheetRows[$i], $rowNumber, $encodingIssues);
            $line = trim(implode(' ', array_map(static fn ($x): string => trim((string) $x), $data)));
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)$/', $line, $m) === 1) {
                if ($descBuffer === []) {
                    continue;
                }
                $description = trim(implode(' ', $descBuffer));
                $descBuffer = [];
                $rows[] = $this->buildJsRow($rowNumber, $description, (int) $m[1], $m[2]);

                continue;
            }

            if (preg_match('/^(.*\S)\s+(\d+)\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)$/', $line, $m) === 1) {
                $description = trim((string) $m[1]);
                if ($descBuffer !== []) {
                    $description = trim(implode(' ', $descBuffer).' '.$description);
                    $descBuffer = [];
                }
                $rows[] = $this->buildJsRow($rowNumber, $description, (int) $m[2], $m[3]);

                continue;
            }

            $descBuffer[] = $line;
        }

        return $rows;
    }

    /**
     * @return array{0:array<int,string>,1:array{vendor_currency_code?:string,vendor_product_total?:string|null,format_vendor_currency_code?:string|null}}
     */
    private function readToHeaderAndPreambleMeta($fh, array &$encodingIssues): array
    {
        $rowNumber = 0;
        $meta = [];

        while (($row = fgetcsv($fh)) !== false) {
            $rowNumber++;
            $row = $this->sanitizeCsvRow($row, $rowNumber, $encodingIssues);
            if ($row === []) {
                continue;
            }

            if ($this->looksLikeJsHeaderRow($row)) {
                return [$row, $meta];
            }

            $maybeHeader = $this->headerMap($row);
            $looksHeader = array_key_exists(self::COL_SKU, $maybeHeader)
                && (
                    array_key_exists(self::COL_UNIT_COST, $maybeHeader)
                    || array_key_exists(self::COL_DSPIAE_WHOLESALE_PRICE, $maybeHeader)
                    || array_key_exists(self::COL_STEDI_WHOLESALE_PRICE_HKD, $maybeHeader)
                    || array_key_exists(self::COL_STEDI_UNIT_PRICE_HKD, $maybeHeader)
                    || array_key_exists(self::COL_UNIT_PRICE_HKD, $maybeHeader)
                    || array_key_exists(self::COL_AL_QUOTE, $maybeHeader)
                    || array_key_exists(self::COL_PLAMOD_UNIT_PRICE, $maybeHeader)
                );

            if ($looksHeader) {
                $map = $maybeHeader;
                $this->detectFormat($map);

                return [$row, $meta];
            }

            $parsed = $this->parseVendorTotalFromPreambleRow($row);
            if ($parsed !== null) {
                $meta = array_merge($meta, $parsed);
            }

            if ($rowNumber > 50) {
                break;
            }
        }

        throw new PurchaseOrderImportException('Could not find a valid CSV header row.');
    }

    /**
     * @param  array<int, string>  $row
     */
    private function looksLikeJsHeaderRow(array $row): bool
    {
        $joined = trim(implode(' ', array_map(static fn ($x): string => trim((string) $x), $row)));
        if ($joined === '') {
            return false;
        }
        $joined = preg_replace('/\s+/u', ' ', $joined) ?? $joined;

        return strtolower($joined) === strtolower(self::COL_JS_PDF_TEXT_HEADER);
    }

    /**
     * @param  resource  $fh
     * @param  array<int, array{kind:string,row:int,col:int}>  $encodingIssues
     * @return array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>
     */
    private function parseJsPdfTextRows($fh, int &$rowNumber, array &$encodingIssues): array
    {
        $rows = [];
        $descBuffer = [];

        while (($data = fgetcsv($fh)) !== false) {
            $rowNumber++;
            $data = $this->sanitizeCsvRow($data, $rowNumber, $encodingIssues);
            $line = trim(implode(' ', array_map(static fn ($x): string => trim((string) $x), $data)));
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)$/', $line, $m) === 1) {
                if ($descBuffer === []) {
                    continue;
                }
                $description = trim(implode(' ', $descBuffer));
                $descBuffer = [];
                $rows[] = $this->buildJsRow($rowNumber, $description, (int) $m[1], $m[2]);

                continue;
            }

            if (preg_match('/^(.*\S)\s+(\d+)\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)$/', $line, $m) === 1) {
                $description = trim((string) $m[1]);
                if ($descBuffer !== []) {
                    $description = trim(implode(' ', $descBuffer).' '.$description);
                    $descBuffer = [];
                }
                $rows[] = $this->buildJsRow($rowNumber, $description, (int) $m[2], $m[3]);

                continue;
            }

            $descBuffer[] = $line;
        }

        return $rows;
    }

    /**
     * @return array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }
     */
    private function buildJsRow(int $rowNumber, string $description, int $qty, string $unitCost): array
    {
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);

        return [
            'row' => $rowNumber,
            'sku' => $this->jsSkuForDescription($description),
            'product_name' => $description !== '' ? $description : null,
            'barcode' => null,
            'unit_cost' => trim($unitCost),
            'qty_ordered' => max(0, $qty),
            'qty_shipped' => null,
            'qty_received' => null,
            'vendor_line_total' => null,
        ];
    }

    private function jsSkuForDescription(string $description): string
    {
        $norm = mb_strtolower(trim($description));
        $compact = preg_replace('/[^a-z0-9]+/u', '', $norm) ?? '';
        $compact = strtoupper(substr($compact, 0, 16));
        if ($compact === '') {
            $compact = 'ITEM';
        }
        $hash = strtoupper(substr(hash('crc32b', $norm !== '' ? $norm : $description), 0, 8));

        return "JS-{$compact}-{$hash}";
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<int, array{kind:string,row:int,col:int}>  $encodingIssues
     * @return array<int, string>
     */
    private function sanitizeCsvRow(array $row, int $rowNumber, array &$encodingIssues): array
    {
        foreach ($row as $i => $cell) {
            $original = (string) $cell;
            $fixed = $this->sanitizeUtf8($original);
            if ($fixed !== $original && count($encodingIssues) < 25) {
                $encodingIssues[] = [
                    'kind' => 'invalid_encoding',
                    'row' => $rowNumber,
                    'col' => $i + 1,
                ];
            }
            $row[$i] = $fixed;
        }

        foreach ($row as $i => $cell) {
            if (! $this->isValidUtf8((string) $cell)) {
                throw new PurchaseOrderImportException('CSV contains invalid text encoding. Please re-save the file as UTF-8 and retry.', [
                    ['kind' => 'invalid_encoding', 'row' => $rowNumber, 'col' => $i + 1],
                ]);
            }
        }

        return $row;
    }

    private function sanitizeUtf8(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if ($this->isValidUtf8($value)) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            /** @var string $converted */
            $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8,Windows-1252,ISO-8859-1');
            $value = $converted;
        }

        if (function_exists('iconv')) {
            $scrubbed = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($scrubbed)) {
                $value = $scrubbed;
            }
        }

        return $value;
    }

    private function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * @param  array<int,string>  $row
     * @return array{vendor_currency_code:string,vendor_product_total:string}|null
     */
    private function parseVendorTotalFromPreambleRow(array $row): ?array
    {
        foreach ($row as $i => $cell) {
            $text = trim((string) $cell);
            if ($text === '') {
                continue;
            }

            if (! preg_match('/Price\s*\/\s*([A-Za-z]{3})/i', $text, $m)) {
                continue;
            }

            $currency = strtoupper((string) $m[1]);
            $total = null;
            for ($j = $i + 1; $j < count($row); $j++) {
                $clean = preg_replace('/[^0-9\.\-]/', '', (string) $row[$j]) ?? '';
                $clean = trim($clean);
                if ($clean === '' || ! is_numeric($clean)) {
                    continue;
                }
                $total = $clean;
            }

            if ($total === null) {
                return ['vendor_currency_code' => $currency, 'vendor_product_total' => '0.00'];
            }

            return [
                'vendor_currency_code' => $currency,
                'vendor_product_total' => number_format((float) $total, 2, '.', ''),
            ];
        }

        return null;
    }

    /**
     * @param  array{vendor_currency_code?:string,vendor_product_total?:string|null,format_vendor_currency_code?:string|null}  $preambleMeta
     * @return array{vendor_currency_code?:string,vendor_product_total?:string|null,format_vendor_currency_code?:string|null}
     */
    private function mergeFormatCurrencyFallback(array $preambleMeta, string $format): array
    {
        if (array_key_exists('vendor_currency_code', $preambleMeta) && trim((string) ($preambleMeta['vendor_currency_code'] ?? '')) !== '') {
            return $preambleMeta;
        }

        if (in_array($format, ['dspiae', 'stedi_tools', 'stedi_simple', 'simple_hkd', 'pm_invoice'], true)) {
            $preambleMeta['vendor_currency_code'] = 'HKD';
            $preambleMeta['format_vendor_currency_code'] = 'HKD';
        }

        return $preambleMeta;
    }

    /**
     * @param  array{
     *   shipping_total?:string|null,
     *   product_total?:string|null,
     *   surcharge_total?:string|null
     * }  $meta
     * @param  array{vendor_product_total?:string|null}  $preambleMeta
     */
    private function mergeAppendHeaderTotals(PurchaseOrder $po, array $meta, array $preambleMeta): void
    {
        $incomingVendorProduct = isset($preambleMeta['vendor_product_total'])
            ? trim((string) $preambleMeta['vendor_product_total'])
            : '';
        if ($incomingVendorProduct !== '' && is_numeric($incomingVendorProduct)) {
            $po->vendor_product_total = $this->addDecimalMoney(
                $this->moneyOrZero($po->vendor_product_total),
                $incomingVendorProduct,
            );
        }

        if (array_key_exists('product_total', $meta) && $meta['product_total'] !== null) {
            $incomingProduct = trim((string) $meta['product_total']);
            if ($incomingProduct !== '' && is_numeric($incomingProduct)) {
                $po->product_total = $this->addDecimalMoney(
                    $this->moneyOrZero($po->product_total),
                    $incomingProduct,
                );
            }
        }

        if (array_key_exists('shipping_total', $meta) && $meta['shipping_total'] !== null) {
            $incomingShipping = trim((string) $meta['shipping_total']);
            if ($incomingShipping !== '' && is_numeric($incomingShipping)) {
                $po->shipping_total = $this->addDecimalMoney(
                    $this->moneyOrZero($po->shipping_total),
                    $incomingShipping,
                );
            }
        }

        if (array_key_exists('surcharge_total', $meta) && $meta['surcharge_total'] !== null) {
            $incomingSurcharge = trim((string) $meta['surcharge_total']);
            if ($incomingSurcharge !== '' && is_numeric($incomingSurcharge)) {
                $po->surcharge_total = $this->addDecimalMoney(
                    $this->moneyOrZero($po->surcharge_total),
                    $incomingSurcharge,
                );
            }
        }
    }

    private function moneyOrZero(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' && is_numeric($trimmed) ? $trimmed : '0.00';
    }

    private function addDecimalMoney(string $a, string $b, int $scale = 2): string
    {
        if (extension_loaded('bcmath')) {
            /** @var string $out */
            $out = bcadd($a, $b, $scale);

            return $out;
        }

        return number_format(((float) $a) + ((float) $b), $scale, '.', '');
    }

    /**
     * @param  array{product_total?:string|null, product_total_includes_fees?:bool}  $meta
     * @param  array{vendor_product_total?:string|null, vendor_freight_total?:string|null}  $preambleMeta
     */
    private function applyAllInFeesCadSplit(array &$meta, array $preambleMeta): void
    {
        if (! $this->productTotalIncludesFees($meta)) {
            return;
        }

        $totalPaid = array_key_exists('product_total', $meta) && $meta['product_total'] !== null
            ? trim((string) $meta['product_total'])
            : '';
        if ($totalPaid === '' || ! is_numeric($totalPaid)) {
            throw new PurchaseOrderImportException(
                'Total paid (CAD) is required when product total includes all fees.',
            );
        }

        $productHkd = isset($preambleMeta['vendor_product_total'])
            ? trim((string) $preambleMeta['vendor_product_total'])
            : '';
        if ($productHkd === '' || ! is_numeric($productHkd) || (float) $productHkd <= 0) {
            throw new PurchaseOrderImportException(
                'Could not derive product total (HKD) from invoice lines for all-fees split.',
            );
        }

        $freightHkd = isset($preambleMeta['vendor_freight_total'])
            ? trim((string) $preambleMeta['vendor_freight_total'])
            : '';
        if ($freightHkd === '' || ! is_numeric($freightHkd) || (float) $freightHkd <= 0) {
            throw new PurchaseOrderImportException(
                'Could not find a freight/shipping line (HKD) in the invoice. Uncheck "includes all fees" and enter product and shipping totals separately.',
            );
        }

        $split = $this->splitTotalPaidCadByHkdRatio($totalPaid, $productHkd, $freightHkd);
        $meta['product_total'] = $split['product_total_cad'];
        $meta['shipping_total'] = $split['shipping_total_cad'];
        $meta['shipping_currency_mode'] = 'cad';
    }

    /**
     * @return array{product_total_cad:string, shipping_total_cad:string}
     */
    private function splitTotalPaidCadByHkdRatio(string $totalPaidCad, string $productHkd, string $freightHkd): array
    {
        $invoiceHkd = (float) $productHkd + (float) $freightHkd;
        if ($invoiceHkd <= 0) {
            throw new PurchaseOrderImportException('Invoice HKD total must be greater than zero.');
        }

        $productShare = (float) $productHkd / $invoiceHkd;
        $productCad = $this->mulDecimalRounded(
            $totalPaidCad,
            number_format($productShare, 8, '.', ''),
            2,
        );

        if (extension_loaded('bcmath')) {
            $shippingCad = bcsub($totalPaidCad, $productCad, 2);
        } else {
            $shippingCad = number_format(((float) $totalPaidCad) - ((float) $productCad), 2, '.', '');
        }

        return [
            'product_total_cad' => $productCad,
            'shipping_total_cad' => $shippingCad,
        ];
    }

    /**
     * @param  array{product_total_includes_fees?:bool}  $meta
     */
    private function productTotalIncludesFees(array $meta): bool
    {
        return array_key_exists('product_total_includes_fees', $meta)
            && filter_var($meta['product_total_includes_fees'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<int, array<int, string>>  $sheetRows
     * @param  array<string, int>  $map
     * @param  array<int, array{kind:string,row:int,col:int}>  $encodingIssues
     */
    private function extractPmInvoiceFreightHkdFromSheetRows(
        array $sheetRows,
        int $headerRowIndex,
        array $map,
        array &$encodingIssues,
    ): ?string {
        $sum = 0.0;
        $hasAny = false;

        for ($i = $headerRowIndex + 1; $i < count($sheetRows); $i++) {
            $rowNumber = $i + 1;
            $data = $this->sanitizeCsvRow($sheetRows[$i], $rowNumber, $encodingIssues);
            if ($this->isBlankRow($data) || ! $this->isPmFreightSheetRow($data)) {
                continue;
            }

            $amount = $this->extractPmInvoiceFreightAmountHkd($data, $map);
            if ($amount === null || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $sum += (float) $amount;
            $hasAny = true;
        }

        if (! $hasAny) {
            return null;
        }

        return number_format($sum, 2, '.', '');
    }

    /**
     * PM broker invoices often place freight in a footer row where the service
     * description sits outside the Item column (e.g. under unit price).
     *
     * @param  array<int, string>  $data
     */
    private function isPmFreightSheetRow(array $data): bool
    {
        $parts = [];
        foreach ($data as $cell) {
            $text = strtolower(trim((string) $cell));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($parts === []) {
            return false;
        }

        $joined = implode(' ', $parts);

        foreach (['shipping address', 'shipment box', 'shipment tracking', 'payment instruction', 'wise remit'] as $exclude) {
            if (str_contains($joined, $exclude)) {
                return false;
            }
        }

        foreach (['air shipping', 'shipping service', 'local tracking', 'freight', 'courier'] as $needle) {
            if (str_contains($joined, $needle)) {
                return true;
            }
        }

        return str_contains($joined, 'shipping') && ! str_contains($joined, 'address');
    }

    /**
     * @param  array<int, string>  $data
     * @param  array<string, int>  $map
     */
    private function extractPmInvoiceFreightAmountHkd(array $data, array $map): ?string
    {
        $candidates = [];

        if (array_key_exists(self::COL_PM_AMOUNT, $map)) {
            $candidates[] = $this->stringAt($data, $map[self::COL_PM_AMOUNT]);
        }

        foreach ($data as $cell) {
            $text = trim((string) $cell);
            if ($text !== '') {
                $candidates[] = $text;
            }
        }

        foreach ($candidates as $candidate) {
            $parsed = $this->parseHkdMoneyCell($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function parseHkdMoneyCell(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/hk\$?|\d/', $trimmed)) {
            return null;
        }

        $clean = str_replace([',', ' '], '', $trimmed);
        $clean = preg_replace('/[^0-9\.\-]/', '', $clean) ?? '';
        $clean = trim($clean);
        if ($clean === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * @param  array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>  $rows
     */
    private function deriveVendorProductTotalFromRows(array $rows): ?string
    {
        $sum = 0.0;
        $hasAny = false;

        foreach ($rows as $r) {
            $lineTotalRaw = array_key_exists('vendor_line_total', $r) ? $r['vendor_line_total'] : null;
            if (is_string($lineTotalRaw) && trim($lineTotalRaw) !== '' && is_numeric($lineTotalRaw)) {
                $sum += (float) $lineTotalRaw;
                $hasAny = true;

                continue;
            }

            $unit = $r['unit_cost'] ?? null;
            $qty = $r['qty_ordered'] ?? null;
            if (! is_string($unit) || trim($unit) === '' || ! is_numeric($unit)) {
                continue;
            }
            if (! is_int($qty) || $qty <= 0) {
                continue;
            }

            $sum += ((float) $unit) * $qty;
            $hasAny = true;
        }

        if (! $hasAny) {
            return null;
        }

        return number_format($sum, 2, '.', '');
    }

    private function deriveFxRateToCad(?string $productTotalCad, ?string $vendorProductTotal, string $vendorCurrencyCode): ?string
    {
        $currency = strtoupper(trim($vendorCurrencyCode));
        if ($currency === '' || $currency === 'CAD') {
            return null;
        }

        $cad = $productTotalCad !== null ? trim($productTotalCad) : '';
        $vendor = $vendorProductTotal !== null ? trim($vendorProductTotal) : '';
        if ($cad === '' || $vendor === '') {
            return null;
        }
        if (! is_numeric($cad) || ! is_numeric($vendor)) {
            return null;
        }
        if ((float) $vendor <= 0) {
            return null;
        }

        if (! extension_loaded('bcmath')) {
            $rate = ((float) $cad) / ((float) $vendor);

            return number_format($rate, 6, '.', '');
        }

        /** @var string $out */
        $out = bcdiv($cad, $vendor, 6);

        return $out;
    }

    private function mulDecimalRounded(string $a, string $b, int $scale): string
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === '' || $b === '' || ! is_numeric($a) || ! is_numeric($b)) {
            return number_format(0, $scale, '.', '');
        }

        if (extension_loaded('bcmath')) {
            $extra = $scale + 2;
            /** @var string $raw */
            $raw = bcmul($a, $b, $extra);

            $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'5';
            $adjusted = str_starts_with($raw, '-')
                ? bcsub($raw, $increment, $extra)
                : bcadd($raw, $increment, $extra);

            /** @var string $out */
            $out = bcadd($adjusted, '0', $scale);

            return $out;
        }

        $value = round(((float) $a) * ((float) $b), $scale);

        return number_format($value, $scale, '.', '');
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function stringAt(array $row, int $idx): string
    {
        if (! array_key_exists($idx, $row)) {
            return '';
        }

        return trim((string) $row[$idx]);
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableStringAt(array $row, int $idx): ?string
    {
        $value = $this->stringAt($row, $idx);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableIntAt(array $row, int $idx): ?int
    {
        $raw = $this->stringAt($row, $idx);
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/^-?\d+$/', $raw)) {
            throw new PurchaseOrderImportException("Invalid integer value: {$raw}");
        }

        return (int) $raw;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableDecimalAt(array $row, int $idx): ?string
    {
        $raw = $this->stringAt($row, $idx);
        if ($raw === '') {
            return null;
        }
        $clean = str_replace([','], '', $raw);
        $clean = preg_replace('/[^0-9\.\-]/', '', $clean) ?? '';
        $clean = trim($clean);
        if ($clean === '') {
            return null;
        }
        if (! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            throw new PurchaseOrderImportException("Invalid decimal value: {$raw}");
        }

        return $clean;
    }

    private function divideDecimal(string $numerator, int $denominator, int $scale): string
    {
        $num = trim($numerator);
        if ($num === '') {
            return '0';
        }
        if (! extension_loaded('bcmath')) {
            $value = ((float) $num) / max(1, $denominator);

            return number_format($value, $scale, '.', '');
        }

        /** @var string $out */
        $out = bcdiv($num, (string) max(1, $denominator), $scale);

        return $out;
    }

    /**
     * @param  array<int, string>  $skus
     * @return array<string, Product>
     */
    private function indexProductsBySkuAndVendor(array $skus, string $vendor): array
    {
        $out = [];
        foreach ($skus as $sku) {
            $matches = $this->products->findBySkuAndVendor($sku, $vendor);
            if ($matches->count() !== 1) {
                continue;
            }
            /** @var Product $p */
            $p = $matches->first();
            $out[(string) $p->sku] = $p;
        }

        return $out;
    }

    /**
     * @param  array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>  $rows
     * @return array<string, Product>
     */
    private function resolveOrCreateProductsBySku(string $vendor, array $rows): array
    {
        $vendor = trim($vendor);

        $skus = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['sku'], $rows)));
        $existing = $this->products->findBySkus($skus)->keyBy('sku');

        foreach ($rows as $r) {
            $sku = (string) $r['sku'];
            /** @var Product|null $p */
            $p = $existing->get($sku);
            if ($p === null) {
                $description = (string) ($r['product_name'] ?? '');
                $description = trim($description) !== '' ? trim($description) : $sku;
                $type = $this->types->deriveFromName($description) ?? 'Others';

                $product = new Product;
                $product->sku = $sku;
                $product->barcode = $this->normalizeBarcode(isset($r['barcode']) ? (string) ($r['barcode'] ?? '') : null);
                $product->description = $description;
                $product->type = $type;
                $product->vendor = $vendor;
                $this->products->create($product);

                $existing->put($sku, $product);

                continue;
            }

            $changed = false;

            $barcode = $this->normalizeBarcode(isset($r['barcode']) ? (string) ($r['barcode'] ?? '') : null);
            if ($barcode !== null && $barcode !== '' && $barcode !== (string) ($p->barcode ?? '')) {
                $p->barcode = $barcode;
                $changed = true;
            }

            $name = isset($r['product_name']) ? trim((string) ($r['product_name'] ?? '')) : '';
            if ($name !== '' && $name !== (string) $p->description) {
                $p->description = $name;
                $changed = true;
            }

            if ($p->vendor !== $vendor) {
                $p->vendor = $vendor;
                $changed = true;
            }

            if (($p->type === null || trim((string) $p->type) === '') && $p->description !== '') {
                $p->type = $this->types->deriveFromName($p->description) ?? 'Others';
                $changed = true;
            }

            if ($changed) {
                $this->products->save($p);
            }
        }

        /** @var array<string, Product> $out */
        $out = [];
        foreach ($skus as $sku) {
            /** @var Product|null $p */
            $p = $existing->get($sku);
            if ($p !== null) {
                $out[$sku] = $p;
            }
        }

        return $out;
    }

    private function normalizeBarcode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        // If Excel exported scientific notation, convert it to digits.
        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*[eE]\s*\+?\s*([0-9]+)\s*$/', $v, $m) === 1) {
            $mantissa = (string) $m[1];
            $exp = (int) $m[2];

            $parts = explode('.', $mantissa, 2);
            $whole = $parts[0] ?? '0';
            $frac = $parts[1] ?? '';
            $digits = $whole.$frac;
            $decimals = strlen($frac);
            $shift = $exp - $decimals;
            if ($shift >= 0) {
                $v = $digits.str_repeat('0', $shift);
            } else {
                $v = substr($digits, 0, max(1, strlen($digits) + $shift));
            }
        }

        // Keep digits only.
        $digits = preg_replace('/\D+/', '', $v) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function normalizeAlSku(string $sku): string
    {
        $normalized = strtoupper(trim($sku));
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, 'BAN')) {
            $normalized = substr($normalized, 3);
        }
        $normalized = trim($normalized);

        if (preg_match('/^\d+\.00$/', $normalized) === 1) {
            $normalized = substr($normalized, 0, -3);
        }

        return trim($normalized);
    }
}

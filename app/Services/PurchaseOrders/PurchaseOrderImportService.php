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
use App\Services\Products\ProductTypeDerivationService;
use App\Services\Products\ProductLatestCostCacheService;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderImportException;
use Carbon\CarbonImmutable;
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

    public function __construct(
        private readonly ProductRepository $products,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
        private readonly ProductTypeDerivationService $types,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    /**
     * @param  array{
     *   vendor:string,
     *   purchase_order_uuid?:string,
     *   ordered_date?:string|null,
     *   shipped_date?:string|null,
     *   received_date?:string|null,
     *   fully_on_shelves_date?:string|null,
     *   shipping_total?:string|null,
     *   product_total?:string|null,
     *   surcharge_total?:string|null,
     *   notes?:string|null
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

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new PurchaseOrderImportException('Uploaded file is not readable.');
        }

        try {
            $encodingIssues = [];
            [$header, $preambleMeta] = $this->readToHeaderAndPreambleMeta($fh, $encodingIssues);
            $map = $this->headerMap($header);
            $format = $this->detectFormat($map);

            $rows = [];
            $rowNumber = 1; // header row
            while (($data = fgetcsv($fh)) !== false) {
                $rowNumber++;
                $data = $this->sanitizeCsvRow($data, $rowNumber, $encodingIssues);
                if ($this->isBlankRow($data)) {
                    continue;
                }

                if ($format === 'standard') {
                    $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
                    if ($sku === '') {
                        throw new PurchaseOrderImportException('Missing SKU value.', [
                            ['row' => $rowNumber, 'kind' => 'missing_sku'],
                        ]);
                    }

                    $rows[] = [
                        'row' => $rowNumber,
                        'sku' => $sku,
                        'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_UNIT_COST]),
                        'qty_ordered' => $this->nullableIntAt($data, $map[self::COL_QTY_ORDERED]),
                        'qty_shipped' => $this->nullableIntAt($data, $map[self::COL_QTY_SHIPPED]),
                        'qty_received' => $this->nullableIntAt($data, $map[self::COL_QTY_RECEIVED]),
                        'product_name' => null,
                        'barcode' => null,
                    ];
                } else {
                    $sku = $this->stringAt($data, $map[self::COL_SKU] ?? -1);
                    if ($sku === '') {
                        continue;
                    }

                    $qtyOrdered = $this->nullableIntAt($data, $map[self::COL_DSPIAE_REQUIRED_QTY]);
                    if (($qtyOrdered ?? 0) <= 0) {
                        continue;
                    }

                    $rows[] = [
                        'row' => $rowNumber,
                        'sku' => $sku,
                        'product_name' => $this->nullableStringAt($data, $map[self::COL_DSPIAE_PRODUCT_NAME] ?? -1),
                        'barcode' => $this->nullableStringAt($data, $map[self::COL_DSPIAE_BARCODE] ?? -1),
                        'unit_cost' => $this->nullableDecimalAt($data, $map[self::COL_DSPIAE_WHOLESALE_PRICE]),
                        'qty_ordered' => $qtyOrdered,
                        'qty_shipped' => null,
                        'qty_received' => null,
                    ];
                }
            }

            if ($rows === []) {
                throw new PurchaseOrderImportException('No rows found in CSV.');
            }

            $productsBySku = $this->resolveOrCreateProductsBySku($vendor, $rows);

            return DB::transaction(function () use ($meta, $vendor, $rows, $productsBySku, $preambleMeta): array {
                $po = $this->resolveOrCreatePurchaseOrder($meta, $vendor, $preambleMeta);
                $po->fx_rate_to_cad = $this->deriveFxRateToCad(
                    $po->product_total !== null ? (string) $po->product_total : null,
                    $po->vendor_product_total !== null ? (string) $po->vendor_product_total : null,
                    (string) $po->vendor_currency_code,
                );
                $this->purchaseOrders->save($po);

                $totalReceived = 0;
                foreach ($rows as $r) {
                    $qtyReceived = (int) ($r['qty_received'] ?? 0);
                    if ($qtyReceived > 0) {
                        $totalReceived += $qtyReceived;
                    }
                }

                $shippingPerUnit = null;
                $shippingTotal = $po->shipping_total !== null ? trim((string) $po->shipping_total) : null;
                if ($shippingTotal !== null && $shippingTotal !== '' && $totalReceived > 0) {
                    $shippingPerUnit = $this->divideDecimal($shippingTotal, $totalReceived, 6);
                }

                $items = 0;
                $lots = 0;

                foreach ($rows as $r) {
                    /** @var Product $product */
                    $product = $productsBySku[(string) $r['sku']];

                    $item = new PurchaseOrderItem();
                    $item->purchase_order_id = $po->id;
                    $item->product_id = (int) $product->id;
                    $item->sku = (string) $product->sku;
                    $item->vendor = (string) ($product->vendor ?? $vendor);
                    $item->vendor_unit_cost = $po->vendor_currency_code !== 'CAD' ? $r['unit_cost'] : null;
                    $item->unit_cost = $po->vendor_currency_code !== 'CAD'
                        ? ($item->vendor_unit_cost !== null && $po->fx_rate_to_cad !== null ? $this->mulDecimalRounded((string) $item->vendor_unit_cost, (string) $po->fx_rate_to_cad, 4) : null)
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

                    $lot = new InventoryLot();
                    $lot->product_id = (int) $product->id;
                    $lot->purchase_order_item_id = $item->id;
                    $lot->source_type = 'po';
                    $lot->unit_cost = $item->unit_cost;
                    $lot->shipping_per_unit = $shippingPerUnit;
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

                $this->latestCosts->recomputeForSkus(array_values(array_unique(array_map(static fn (array $r): string => (string) $r['sku'], $rows))));

                return [
                    'purchase_order_uuid' => (string) $po->uuid,
                    'items' => $items,
                    'lots' => $lots,
                    'shipping_per_unit' => $shippingPerUnit,
                ];
            });
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param  array{
     *   vendor:string,
     *   purchase_order_uuid?:string,
     *   ordered_date?:string|null,
     *   shipped_date?:string|null,
     *   received_date?:string|null,
     *   fully_on_shelves_date?:string|null,
     *   shipping_total?:string|null,
     *   product_total?:string|null,
     *   surcharge_total?:string|null,
     *   notes?:string|null
     * } $meta
     * @param  array{vendor_currency_code?:string,vendor_product_total?:string|null}  $preambleMeta
     */
    private function resolveOrCreatePurchaseOrder(array $meta, string $vendor, array $preambleMeta): PurchaseOrder
    {
        $uuid = array_key_exists('purchase_order_uuid', $meta) ? trim((string) $meta['purchase_order_uuid']) : '';

            if ($uuid !== '') {
            $po = $this->purchaseOrders->findByUuidOrFail($uuid);
            if (! $po->relationLoaded('items')) {
                $po->load('items');
            }

            $itemIds = $po->items->pluck('id')->all();
            $hasReceived = $po->items->contains(static fn ($it): bool => ((int) ($it->qty_received ?? 0)) > 0);
            $lots = $this->inventory->countLotsForPurchaseOrderItems($itemIds);
            if ($hasReceived || $lots > 0) {
                throw new PurchaseOrderImportException('Re-import blocked: this PO has received inventory/lots. Create a new PO instead.', [
                    ['kind' => 'reimport_not_allowed', 'purchase_order_uuid' => (string) $po->uuid],
                ]);
            }

            // Replace all items for a clean re-import.
            $this->purchaseOrders->deleteItemsForPurchaseOrder((int) $po->id);

            // Update header fields (only when present in request).
            $po->vendor = $vendor;
            $po->vendor_currency_code = $preambleMeta['vendor_currency_code'] ?? $po->vendor_currency_code ?? 'CAD';
            $po->vendor_product_total = $preambleMeta['vendor_product_total'] ?? $po->vendor_product_total;
            if (array_key_exists('ordered_date', $meta)) $po->ordered_date = $meta['ordered_date'];
            if (array_key_exists('shipped_date', $meta)) $po->shipped_date = $meta['shipped_date'];
            if (array_key_exists('received_date', $meta)) $po->received_date = $meta['received_date'];
            if (array_key_exists('fully_on_shelves_date', $meta)) $po->fully_on_shelves_date = $meta['fully_on_shelves_date'];
            if (array_key_exists('shipping_total', $meta)) $po->shipping_total = $meta['shipping_total'];
            if (array_key_exists('product_total', $meta)) $po->product_total = $meta['product_total'];
            if (array_key_exists('surcharge_total', $meta)) $po->surcharge_total = $meta['surcharge_total'];
            if (array_key_exists('notes', $meta)) $po->notes = $meta['notes'];

            return $po;
        }

        $po = new PurchaseOrder();
        $po->vendor = $vendor;
        $po->vendor_currency_code = $preambleMeta['vendor_currency_code'] ?? 'CAD';
        $po->vendor_product_total = $preambleMeta['vendor_product_total'] ?? null;
        $po->ordered_date = array_key_exists('ordered_date', $meta) ? $meta['ordered_date'] : null;
        $po->shipped_date = array_key_exists('shipped_date', $meta) ? $meta['shipped_date'] : null;
        $po->received_date = array_key_exists('received_date', $meta) ? $meta['received_date'] : null;
        $po->fully_on_shelves_date = array_key_exists('fully_on_shelves_date', $meta) ? $meta['fully_on_shelves_date'] : null;
        $po->shipping_total = array_key_exists('shipping_total', $meta) ? $meta['shipping_total'] : null;
        $po->product_total = array_key_exists('product_total', $meta) ? $meta['product_total'] : null;
        $po->surcharge_total = array_key_exists('surcharge_total', $meta) ? $meta['surcharge_total'] : null;
        $po->notes = array_key_exists('notes', $meta) ? $meta['notes'] : null;
        $this->purchaseOrders->create($po);

        return $po;
    }

    private function resolveReceivedAt(?string $receivedDate, ?string $shippedDate, ?string $orderedDate): \DateTimeInterface
    {
        $candidate = $receivedDate ?? $shippedDate ?? $orderedDate;
        if ($candidate === null || trim($candidate) === '') {
            return now();
        }

        return CarbonImmutable::parse($candidate)->startOfDay();
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
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     */
    private function detectFormat(array $map): string
    {
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
        foreach ($dspiaeCols as $col) {
            if (! array_key_exists($col, $map)) {
                throw new PurchaseOrderImportException("Missing required column: {$col}");
            }
        }

        return 'dspiae';
    }

    /**
     * @return array{0:array<int,string>,1:array{vendor_currency_code?:string,vendor_product_total?:string|null}}
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

            $maybeHeader = $this->headerMap($row);
            $looksHeader = array_key_exists(self::COL_SKU, $maybeHeader)
                && (array_key_exists(self::COL_UNIT_COST, $maybeHeader) || array_key_exists(self::COL_DSPIAE_WHOLESALE_PRICE, $maybeHeader));

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
     *   barcode:string|null
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

                $product = new Product();
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
        if ($value === null) return null;
        $v = trim($value);
        if ($v === '') return null;

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
}



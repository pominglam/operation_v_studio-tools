<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\Product;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ProductInventoryQuantityOverrideImportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    /**
     * @return array{
     *   lines_parsed:int,
     *   unique_barcodes:int,
     *   reset_products:int,
     *   updated_products:int,
     *   backup_before_path:string|null,
     *   uploaded_file_path:string|null,
     *   missing_in_system:array<int,string>,
     *   scoped_purchase_order_uuid:string|null,
     *   forced?:bool,
     *   blocked?:bool,
     *   can_force?:bool
     * }
     */
    public function import(UploadedFile $file, ?string $purchaseOrderUuid = null, bool $force = false, ?string $missingProductsMode = 'set_zero'): array
    {
        $purchaseOrderUuid = is_string($purchaseOrderUuid) ? trim($purchaseOrderUuid) : null;
        $purchaseOrderUuid = $purchaseOrderUuid !== '' ? $purchaseOrderUuid : null;
        $missingProductsMode = trim((string) ($missingProductsMode ?? ''));
        $missingProductsMode = $missingProductsMode !== '' ? $missingProductsMode : 'set_zero';
        $resetMissingToZero = $missingProductsMode !== 'skip';

        [$barcodeCounts, $barcodes, $linesParsed] = $this->parseBarcodePerLineFile($file);

        $missing = [];
        if ($barcodes !== []) {
            $existing = $this->products->findByBarcodes($barcodes);
            $found = [];
            /** @var Product $p */
            foreach ($existing as $p) {
                $b = is_string($p->barcode) ? trim($p->barcode) : '';
                if ($b !== '') {
                    $found['b:'.$b] = true;
                }
            }

            foreach ($barcodes as $b) {
                if (! isset($found['b:'.$b])) {
                    $missing[] = $b;
                }
            }
        }

        if ($missing !== [] && ! $force) {
            // Safety: do not reset or update anything unless forced.
            return [
                'lines_parsed' => $linesParsed,
                'unique_barcodes' => count($barcodeCounts),
                'reset_products' => 0,
                'updated_products' => 0,
                'backup_before_path' => null,
                'uploaded_file_path' => null,
                'missing_in_system' => $missing,
                'scoped_purchase_order_uuid' => $purchaseOrderUuid,
                'forced' => false,
                'blocked' => true,
                'can_force' => true,
            ];
        }

        $backupBeforePath = $this->backupCurrentInventory();
        $uploadedFilePath = $this->storeUploadedFile($file);

        if ($purchaseOrderUuid !== null) {
            $productIds = $this->purchaseOrders->listProductIdsByUuid($purchaseOrderUuid);
            $out = $this->products->overrideAvailableQtyFromBarcodeCountsForProductIds($productIds, $barcodeCounts, $resetMissingToZero);
        } else {
            $out = $this->products->overrideAvailableQtyFromBarcodeCounts($barcodeCounts, $resetMissingToZero);
        }

        return [
            'lines_parsed' => $linesParsed,
            'unique_barcodes' => count($barcodeCounts),
            'reset_products' => (int) ($out['reset'] ?? 0),
            'updated_products' => (int) ($out['updated'] ?? 0),
            'backup_before_path' => $backupBeforePath,
            'uploaded_file_path' => $uploadedFilePath,
            'missing_in_system' => $missing,
            'scoped_purchase_order_uuid' => $purchaseOrderUuid,
            'forced' => $force,
            'blocked' => false,
        ];
    }

    private function storeUploadedFile(UploadedFile $file): string
    {
        $ts = now()->format('Ymd_His');
        $name = "inventory_qty_override_{$ts}.csv";

        /** @var string $path */
        $path = $file->storeAs('imports/inventory-qty-override', $name, 'local');

        return $path;
    }

    private function backupCurrentInventory(): string
    {
        $ts = now()->format('Ymd_His');
        $path = "backups/inventory_override/before_{$ts}.csv";

        $rows = [];
        $rows[] = ['SKU', 'BARCODE', 'DESCRIPTION', 'AVAILABLE_QTY'];

        $all = $this->products->listAll()->sortBy('sku')->values();
        foreach ($all as $p) {
            $rows[] = [
                $p->sku,
                $p->barcode ?? '',
                $p->description,
                $p->available_qty === null ? '' : (string) $p->available_qty,
            ];
        }

        $csv = $this->renderCsv($rows);
        Storage::disk('local')->put($path, $csv);

        return $path;
    }

    /**
     * @return array{0:array<int,array{barcode:string,qty:int}>,1:array<int,string>,2:int} barcodeCounts, barcodes, linesParsed
     */
    private function parseBarcodePerLineFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        $lines = 0;
        $counts = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $lines++;
                $raw = trim($line);
                if ($raw === '') {
                    continue;
                }

                // Allow basic CSV-ish input: first column=barcode, second column=qty (optional).
                // If qty is missing/blank, it means 1.
                $parts = str_getcsv($raw);
                $barcode = trim((string) ($parts[0] ?? ''), " \t\n\r\0\x0B\"");
                if ($barcode === '') {
                    continue;
                }

                $qtyRaw = trim((string) ($parts[1] ?? ''), " \t\n\r\0\x0B\"");
                $qty = 1;
                if ($qtyRaw !== '') {
                    $clean = preg_replace('/[^0-9\-]/', '', $qtyRaw) ?? '';
                    $clean = trim($clean);
                    if ($clean === '' || ! preg_match('/^-?\d+$/', $clean)) {
                        throw new InvalidProductImportFileException("Invalid qty value: {$qtyRaw}");
                    }
                    $qty = (int) $clean;
                }
                if ($qty <= 0) {
                    continue;
                }

                // Barcodes are stored as strings in DB; treat as-is after trim.
                // IMPORTANT: array keys cast numeric strings to ints; prefix to keep keys stable.
                $key = 'b:'.$barcode;
                $counts[$key] = ($counts[$key] ?? 0) + $qty;
            }
        } finally {
            fclose($handle);
        }

        if ($lines === 0) {
            throw new InvalidProductImportFileException('CSV is empty.');
        }

        /** @var array<int, array{barcode:string, qty:int}> $rows */
        $rows = [];
        /** @var array<int, string> $barcodes */
        $barcodes = [];
        foreach ($counts as $k => $qty) {
            $k = (string) $k;
            if (! str_starts_with($k, 'b:')) {
                continue;
            }
            $barcode = substr($k, 2);
            $barcode = is_string($barcode) ? trim($barcode) : '';
            if ($barcode === '') {
                continue;
            }
            $n = (int) $qty;
            if ($n <= 0) {
                continue;
            }
            $rows[] = ['barcode' => $barcode, 'qty' => $n];
            $barcodes[] = $barcode;
        }

        return [$rows, $barcodes, $lines];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function renderCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Failed to render CSV.');
        }

        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }

        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return is_string($out) ? $out : '';
    }
}

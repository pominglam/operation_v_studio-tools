<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductBarcodeImportService;
use Illuminate\Console\Command;

final class ProductsImportBarcodesCommand extends Command
{
    protected $signature = 'products:import-barcodes
        {path : Path to the shipment CSV}
        {--apply : Actually update product barcodes (default is dry-run)}
        {--overwrite : Overwrite existing barcodes}
        {--vendor= : Fallback vendor (used if vendor column is missing/blank)}
        {--vendor-col=7 : 1-based vendor column index when vendor header is missing (default: 7)}
        {--sku-col= : 1-based SKU column index override (optional)}
        {--preview=25 : Preview row limit}';

    protected $description = 'Imports product barcodes from a shipment CSV (match by vendor + sku; update barcode).';

    public function handle(ProductBarcodeImportService $service): int
    {
        $path = (string) $this->argument('path');
        $apply = (bool) $this->option('apply');
        $overwrite = (bool) $this->option('overwrite');
        $vendor = (string) $this->option('vendor');
        $vendor = trim($vendor) !== '' ? $vendor : null;
        $vendorCol = (int) $this->option('vendor-col');
        $skuColRaw = (string) $this->option('sku-col');
        $skuCol = trim($skuColRaw) !== '' ? (int) $skuColRaw : null;
        $preview = (int) $this->option('preview');
        if ($preview < 0) {
            $preview = 0;
        }
        if ($vendorCol < 0) {
            $vendorCol = 0;
        }
        if ($skuCol !== null && $skuCol <= 0) {
            $skuCol = null;
        }

        $this->info($apply ? 'Importing product barcodes…' : 'Dry-run (no changes will be saved)…');
        $this->line('Match: vendor + sku');
        $this->line('Overwrite: '.($overwrite ? 'yes' : 'no'));
        $this->line('Vendor col: '.($vendorCol > 0 ? (string) $vendorCol : '(disabled)'));
        $this->line('SKU col: '.($skuCol !== null ? (string) $skuCol : '(from header)'));
        $this->line('Fallback vendor: '.($vendor ?? '(none)'));

        try {
            $result = $service->importFromShipmentCsv(
                path: $path,
                apply: $apply,
                overwrite: $overwrite,
                fallbackVendor: $vendor,
                vendorColOneBased: $vendorCol,
                skuColOneBased: $skuCol,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Rows read: {$result->rowsRead}");
        $this->info("Matched: {$result->matched}");
        $this->info(($apply ? 'Updated' : 'Would update').": {$result->updatedCount}");
        $this->info("Skipped: {$result->skippedCount}");
        $this->info("Missing: {$result->missingCount}");
        $this->info("Ambiguous: {$result->ambiguousCount}");

        $previewRows = array_slice($result->updated, 0, $preview);
        if ($previewRows !== []) {
            $this->line('');
            $this->table(['Vendor', 'SKU', 'Old barcode', 'New barcode'], array_map(
                static fn (array $r): array => [$r['vendor'], $r['sku'], $r['old'] ?? '—', $r['new']],
                $previewRows,
            ));
        }

        return self::SUCCESS;
    }
}


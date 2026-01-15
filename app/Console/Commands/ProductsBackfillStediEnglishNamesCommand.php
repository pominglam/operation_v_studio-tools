<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\StediEnglishNameBackfillService;
use Illuminate\Console\Command;

final class ProductsBackfillStediEnglishNamesCommand extends Command
{
    protected $signature = 'products:stedi-backfill-english-names
        {path : Path to the Stedi shipment CSV}
        {--apply : Actually update product names (default is dry-run)}
        {--vendor=Stedi : Vendor match (default: Stedi)}
        {--name-source=english_name : Name source: english_name|last_non_empty}
        {--preview=25 : Preview row limit}';

    protected $description = 'Backfills Stedi product names from a shipment CSV (match by vendor + sku; update when name differs).';

    public function handle(StediEnglishNameBackfillService $service): int
    {
        $path = (string) $this->argument('path');
        $apply = (bool) $this->option('apply');
        $vendor = (string) $this->option('vendor');
        $nameSource = (string) $this->option('name-source');
        $preview = (int) $this->option('preview');
        if ($preview < 0) {
            $preview = 0;
        }

        $this->info($apply ? 'Applying Stedi English name backfill…' : 'Dry-run (no changes will be saved)…');
        $this->line("Vendor match: {$vendor}");

        try {
            $result = $service->backfillFromShipmentCsv($path, $apply, $vendor, $nameSource);
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

        $previewRows = array_slice($result->updated, 0, $preview);
        if ($previewRows !== []) {
            $this->line('');
            $this->table(['SKU', 'Old name', 'New name'], array_map(
                static fn (array $r): array => [$r['sku'], $r['old'], $r['new']],
                $previewRows,
            ));
        }

        return self::SUCCESS;
    }
}


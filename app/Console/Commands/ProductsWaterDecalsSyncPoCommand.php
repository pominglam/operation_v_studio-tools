<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\WaterDecalSkuPrefixService;
use App\Services\PurchaseOrders\PurchaseOrderWaterDecalLineAddService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'products:water-decals-sync-po')]
final class ProductsWaterDecalsSyncPoCommand extends Command
{
    protected $signature = 'products:water-decals-sync-po
        {po_uuid : Purchase order UUID}
        {--skip-prefix : Skip WD- SKU prefix migration}
        {--skip-add-lines : Skip adding the three standard decal lines}';

    protected $description = 'Prefix water-decal SKUs with WD- and add missing Stedi decal lines to a PO.';

    public function handle(
        WaterDecalSkuPrefixService $prefixService,
        PurchaseOrderWaterDecalLineAddService $lineAddService,
    ): int {
        $poUuid = trim((string) $this->argument('po_uuid'));

        if (! $this->option('skip-prefix')) {
            $renamed = $prefixService->prefixAll('WD-');
            $this->info('Prefixed '.count($renamed).' water-decal SKU(s) with WD-.');
            if ($renamed !== []) {
                $this->table(['From', 'To'], array_map(static fn (array $row): array => [
                    $row['from'],
                    $row['to'],
                ], $renamed));
            }
        }

        if (! $this->option('skip-add-lines')) {
            $lines = [
                [
                    'sku' => 'WD-MG-224',
                    'description' => 'Water decal - MG Turn A',
                    'type' => 'MG',
                    'vendor_unit_cost_hkd' => '15.0000',
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'WD-PG-13',
                    'description' => 'Water decal - PG W-Gundam Zero Custom',
                    'type' => 'PG',
                    'vendor_unit_cost_hkd' => '24.0000',
                    'qty_ordered' => 1,
                ],
                [
                    'sku' => 'WD-MGEX-01',
                    'description' => 'Water decal - MGEX Strike Freedom',
                    'type' => 'MGEX',
                    'vendor_unit_cost_hkd' => '14.0000',
                    'qty_ordered' => 1,
                ],
            ];

            $created = $lineAddService->addLines($poUuid, $lines);
            $this->info('Added '.count($created).' PO line(s).');
            $this->table(['SKU', 'Product ID', 'PO item ID'], array_map(static fn (array $row): array => [
                $row['sku'],
                (string) $row['product_id'],
                (string) $row['item_id'],
            ], $created));
        }

        return self::SUCCESS;
    }
}

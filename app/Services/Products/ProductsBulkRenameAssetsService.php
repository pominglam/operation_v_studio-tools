<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductsBulkRenameAssetsResultDTO;
use App\Jobs\RenameSelectedProductAssetsJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class ProductsBulkRenameAssetsService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
    ) {}

    /**
     * @param  array<int, string>  $productUuids
     */
    public function queue(array $productUuids): ProductsBulkRenameAssetsResultDTO
    {
        $productUuids = array_values(array_unique(array_filter(array_map('strval', $productUuids), static fn (string $v): bool => trim($v) !== '')));
        if ($productUuids === []) {
            return new ProductsBulkRenameAssetsResultDTO(0, '');
        }

        $existing = $this->products->findByUuids($productUuids)->keyBy('uuid');

        $jobs = [];
        $itemProducts = [];
        foreach ($productUuids as $uuid) {
            $p = $existing->get($uuid);
            if ($p === null) {
                continue;
            }

            $jobs[] = new RenameSelectedProductAssetsJob((string) Str::uuid(), (string) $uuid);
            $itemProducts[] = [
                'product_uuid' => (string) $uuid,
                'sku' => is_string($p->sku ?? null) ? (string) $p->sku : null,
                'vendor' => is_string($p->vendor ?? null) ? (string) $p->vendor : null,
                'debug_log' => '[job] type=rename_assets',
            ];
        }

        if ($jobs === []) {
            return new ProductsBulkRenameAssetsResultDTO(0, '');
        }

        $batch = Bus::batch($jobs)
            ->name('rename_selected_product_assets')
            ->onQueue('pdp_sync')
            ->allowFailures()
            ->dispatch();

        $this->batchItems->insertQueued($batch->id, $itemProducts);

        return new ProductsBulkRenameAssetsResultDTO(
            queued: count($itemProducts),
            batchId: $batch->id,
        );
    }
}

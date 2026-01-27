<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductsRecrawlSelectedResultDTO;
use App\Jobs\RecrawlSelectedProductJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class ProductsRecrawlSelectedService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
    ) {}

    /**
     * @param  array<int, string>  $productUuids
     * @param  array<int, string>  $sources
     */
    public function recrawlSelected(array $productUuids, array $sources): ProductsRecrawlSelectedResultDTO
    {
        $productUuids = array_values(array_unique(array_filter(array_map('strval', $productUuids), static fn (string $v): bool => trim($v) !== '')));
        $sources = array_values(array_unique(array_filter(array_map('strval', $sources), static fn (string $v): bool => trim($v) !== '')));

        if ($productUuids === [] || $sources === []) {
            return new ProductsRecrawlSelectedResultDTO(0, '');
        }

        $jobs = [];
        $itemProducts = [];

        $existing = $this->products->findByUuids($productUuids)->keyBy('uuid');
        foreach ($productUuids as $uuid) {
            $p = $existing->get($uuid);
            if ($p === null) {
                continue;
            }

            $jobs[] = new RecrawlSelectedProductJob((string) Str::uuid(), (string) $uuid, $sources);
            $itemProducts[] = [
                'product_uuid' => (string) $uuid,
                'sku' => is_string($p->sku ?? null) ? (string) $p->sku : null,
                'vendor' => is_string($p->vendor ?? null) ? (string) $p->vendor : null,
            ];
        }

        if ($jobs === []) {
            return new ProductsRecrawlSelectedResultDTO(0, '');
        }

        $batch = Bus::batch($jobs)
            ->name('recrawl_selected_products')
            ->onQueue('pdp_sync')
            ->allowFailures()
            ->dispatch();

        $this->batchItems->insertQueued($batch->id, $itemProducts);

        return new ProductsRecrawlSelectedResultDTO(
            queued: count($itemProducts),
            batchId: $batch->id,
        );
    }
}


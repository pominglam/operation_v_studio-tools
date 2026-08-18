<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Support\PurchaseOrders\ProductLatestArrivedLandedUnitCostResolver;

final class ProductLatestCostCacheService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductLatestArrivedLandedUnitCostResolver $latestArrivedCosts,
    ) {}

    /**
     * @param  array<int, string>  $skus
     * @return array{matched:int, updated:int}
     */
    public function recomputeForSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), static fn (string $s): bool => $s !== '')));
        if ($skus === []) {
            return ['matched' => 0, 'updated' => 0];
        }

        /** @var \Illuminate\Support\Collection<int, Product> $existing */
        $existing = $this->products->findBySkus($skus);
        $bySku = [];
        foreach ($existing as $p) {
            $bySku[$p->sku] = $p;
        }

        $matched = 0;
        $updated = 0;

        foreach ($skus as $sku) {
            if (! isset($bySku[$sku])) {
                continue;
            }
            $matched++;

            $calc = $this->latestArrivedCosts->latestCostsForSku($sku);
            $product = $bySku[$sku];

            $product->latest_unit_cost = $calc['latest_unit_cost'];
            $product->latest_landed_unit_cost = $calc['latest_landed_unit_cost'];
            $this->products->save($product);
            $updated++;
        }

        return ['matched' => $matched, 'updated' => $updated];
    }

    /**
     * @return array{matched:int, updated:int}
     */
    public function recomputeAll(): array
    {
        $all = $this->products->listAll();
        $skus = $all->pluck('sku')->all();

        return $this->recomputeForSkus($skus);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;

final class WaterDecalSkuPrefixService
{
    public function __construct(
        private readonly ProductSkuCascadeRenameService $skuRename,
    ) {}

    /**
     * @return array<int, array{from: string, to: string}>
     */
    public function prefixAll(string $prefix = 'WD-'): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            throw new \InvalidArgumentException('Prefix must not be empty.');
        }

        /** @var array<int, array{from: string, to: string}> $renamed */
        $renamed = [];

        $products = Product::query()
            ->where('main_type', '=', 'water decals')
            ->orderBy('sku')
            ->get();

        foreach ($products as $product) {
            $from = trim((string) $product->sku);
            if ($from === '' || str_starts_with($from, $prefix)) {
                continue;
            }

            $to = $prefix.$from;
            $this->skuRename->rename($from, $to);
            $renamed[] = ['from' => $from, 'to' => $to];
        }

        return $renamed;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\Product;

final class StorePreorderUsesPlamodImagesOnly
{
    /** @var list<string> */
    public const array KEPT_SOURCES = ['plamod', 'plamod_preorder', 'manual_upload'];

    public const string CATALOG_SOURCE_HLJ = 'hlj';

    public function __construct(
        private readonly StorePreorderRepository $offers,
    ) {}

    public function appliesToProduct(Product $product): bool
    {
        return $this->appliesToProductId((int) $product->id);
    }

    public function appliesToProductId(int $productId): bool
    {
        return $this->offers->existsForProductId($productId);
    }

    public function appliesToProductUuid(string $productUuid): bool
    {
        return $this->offers->existsForProductUuid($productUuid);
    }

    public function isKeptAssetSource(string $source): bool
    {
        return in_array(trim($source), self::KEPT_SOURCES, true);
    }

    /**
     * @param  list<string>  $sources
     * @return list<string>
     */
    public function filterRecrawlSources(array $sources): array
    {
        return array_values(array_filter(
            $sources,
            fn (string $source): bool => $this->isKeptAssetSource($source),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use App\Support\Products\WorkshopFacetsFromStorefrontTags;
use App\Support\Products\WorkshopShelfCatalog;

final class ProductWorkshopTaxonomyResolver
{
    public function __construct(
        private readonly ProductStorefrontClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     workshop_shelf: string|null,
     *     workshop_facets: array<string, string|array<int, string>>,
     *     canonical_department: string|null
     * }
     */
    public function resolve(Product $product): array
    {
        $classification = $this->classifier->classify($product);
        $storefrontDepartment = $classification->department;
        if ($storefrontDepartment === null) {
            return [
                'workshop_shelf' => null,
                'workshop_facets' => [],
                'canonical_department' => null,
            ];
        }

        return [
            'workshop_shelf' => WorkshopShelfCatalog::labelForDepartment($storefrontDepartment),
            'workshop_facets' => WorkshopFacetsFromStorefrontTags::parse($classification->storefrontTags),
            'canonical_department' => WorkshopShelfCatalog::canonicalDepartmentFor($storefrontDepartment),
        ];
    }
}

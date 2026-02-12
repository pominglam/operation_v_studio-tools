<?php

declare(strict_types=1);

namespace App\DTOs\Products;

use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;

final class ProductInfoData
{
    /**
     * @param  array<int, ProductExternalContent>  $contents
     * @param  array<int, ProductExternalAsset>  $assets
     */
    public function __construct(
        public array $contents,
        public array $assets,
        public ?string $preferredDescriptionSource = null,
    ) {}
}


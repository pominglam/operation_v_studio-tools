<?php

declare(strict_types=1);

namespace App\DTOs\Products;

use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;

final class PlamodProductData
{
    /**
     * @param  array<int, ProductExternalAsset>  $assets
     */
    public function __construct(
        public ?ProductExternalContent $content,
        public array $assets,
    ) {}
}



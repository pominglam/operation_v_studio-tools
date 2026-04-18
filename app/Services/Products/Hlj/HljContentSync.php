<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use App\Models\Product;

interface HljContentSync
{
    public function syncForProduct(Product $product): void;
}

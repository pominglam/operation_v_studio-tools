<?php

declare(strict_types=1);

namespace App\DTOs\Products;

use App\Models\ProductSavedView;

final readonly class ProductSavedViewWriteResult
{
    public function __construct(
        public ProductSavedView $view,
        public bool $created,
    ) {}
}

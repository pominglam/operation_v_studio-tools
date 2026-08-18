<?php

declare(strict_types=1);

namespace App\DTOs\Products;

final readonly class ProductExternalAssetServePath
{
    public function __construct(
        public string $absolutePath,
        public string $mimeType,
        public string $filename,
    ) {}
}

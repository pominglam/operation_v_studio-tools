<?php

declare(strict_types=1);

namespace App\DTOs\Storefront;

final class ModelKitStorefrontIndexRebuildResult
{
    /**
     * @param  array<int, string>  $themeIds
     */
    public function __construct(
        public readonly int $productCount,
        public readonly int $bytes,
        public readonly array $themeIds,
        public readonly int $writePasses,
        public readonly string $generatedAt,
    ) {}
}

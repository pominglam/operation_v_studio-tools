<?php

declare(strict_types=1);

namespace App\Support\Storefront;

final readonly class ModelKitCollectionFilterManifestResult
{
    /**
     * @param  list<string>  $writtenPaths
     */
    public function __construct(
        public int $handleCount,
        public int $durationMs,
        public string $themeRoot,
        public array $writtenPaths,
    ) {}
}

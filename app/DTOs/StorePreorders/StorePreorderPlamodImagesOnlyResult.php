<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderPlamodImagesOnlyResult
{
    public function __construct(
        public int $productsCleaned,
        public int $assetsRemoved,
        public int $imagePushQueued,
        public bool $dryRun,
    ) {}
}

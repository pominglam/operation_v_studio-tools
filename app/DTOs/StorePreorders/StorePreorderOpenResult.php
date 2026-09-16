<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

use App\Models\StorePreorder;
use Illuminate\Support\Collection;

final readonly class StorePreorderOpenResult
{
    /**
     * @param  Collection<int, StorePreorder>  $offers
     */
    public function __construct(
        public Collection $offers,
        public int $opened,
        public int $reopened,
        public int $skippedOpen,
        public int $productsCreated,
        public int $shopifyQueued = 0,
        public int $photosQueued = 0,
    ) {}
}

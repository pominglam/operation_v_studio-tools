<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderMissingPhotosResult
{
    public function __construct(
        public int $missing,
        public int $attached,
        public int $imagePushQueued,
        public int $queued,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderExpireResult
{
    /**
     * @param  list<array{sku: string, message: string}>  $errors
     */
    public function __construct(
        public int $found,
        public int $closed,
        public int $shopifyPushed,
        public int $shopifyFailed,
        public array $errors,
    ) {}
}

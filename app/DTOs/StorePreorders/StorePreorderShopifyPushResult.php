<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderShopifyPushResult
{
    /**
     * @param  list<array{sku: string, message: string}>  $errors
     */
    public function __construct(
        public int $pushed,
        public int $failed,
        public array $errors,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderShopifyShelfSyncResult
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public int $kept,
        public int $retagged,
        public int $untagged,
        public int $deleted,
        public int $failed,
        public array $failures,
    ) {}
}

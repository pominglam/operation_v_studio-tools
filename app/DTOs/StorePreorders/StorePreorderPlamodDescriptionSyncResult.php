<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderPlamodDescriptionSyncResult
{
    /**
     * @param  list<string>  $updatedSkus
     * @param  list<string>  $failedSkus
     * @param  list<string>  $pushedSkus
     */
    public function __construct(
        public int $attempted,
        public array $updatedSkus,
        public array $failedSkus,
        public array $pushedSkus,
    ) {}
}

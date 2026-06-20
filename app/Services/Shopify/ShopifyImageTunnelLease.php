<?php

declare(strict_types=1);

namespace App\Services\Shopify;

final class ShopifyImageTunnelLease
{
    private bool $released = false;

    public function __construct(
        private readonly ShopifyImageTunnelLeaseService $owner,
        public readonly string $tunnelUrl,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->owner->releaseLease();
    }
}

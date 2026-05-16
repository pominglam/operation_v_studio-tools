<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

interface ShopifySyncRunnerInterface
{
    public function key(): string;

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void;
}

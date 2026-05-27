<?php

declare(strict_types=1);

namespace App\DAL\Shopify;

use App\Models\Shopify\ShopifySyncState;
use Illuminate\Support\Carbon;

interface ShopifySyncStateRepository
{
    public function findByKey(string $syncKey): ?ShopifySyncState;

    public function markRunStarted(string $syncKey): ShopifySyncState;

    public function markRunSucceeded(string $syncKey, ?Carbon $highWaterUpdatedAt): ShopifySyncState;

    public function markRunFailed(string $syncKey, string $error): ShopifySyncState;
}

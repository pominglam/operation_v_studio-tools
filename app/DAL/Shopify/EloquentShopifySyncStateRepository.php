<?php

declare(strict_types=1);

namespace App\DAL\Shopify;

use App\Models\Shopify\ShopifySyncState;
use Illuminate\Support\Carbon;

final class EloquentShopifySyncStateRepository implements ShopifySyncStateRepository
{
    public function findByKey(string $syncKey): ?ShopifySyncState
    {
        /** @var ShopifySyncState|null $row */
        $row = ShopifySyncState::query()->where('sync_key', $syncKey)->first();

        return $row;
    }

    public function markRunStarted(string $syncKey): ShopifySyncState
    {
        /** @var ShopifySyncState $row */
        $row = ShopifySyncState::query()->firstOrCreate(
            ['sync_key' => $syncKey],
            ['last_success_at' => null, 'high_water_updated_at' => null, 'last_error' => null],
        );
        $row->last_run_started_at = now();
        $row->save();

        return $row;
    }

    public function markRunSucceeded(string $syncKey, ?Carbon $highWaterUpdatedAt): ShopifySyncState
    {
        /** @var ShopifySyncState $row */
        $row = ShopifySyncState::query()->firstOrCreate(['sync_key' => $syncKey]);
        $row->last_success_at = now();
        if ($highWaterUpdatedAt !== null) {
            $current = $row->high_water_updated_at;
            if ($current === null || $highWaterUpdatedAt->greaterThan($current)) {
                $row->high_water_updated_at = $highWaterUpdatedAt;
            }
        }
        $row->last_error = null;
        $row->save();

        return $row;
    }

    public function markRunFailed(string $syncKey, string $error): ShopifySyncState
    {
        /** @var ShopifySyncState $row */
        $row = ShopifySyncState::query()->firstOrCreate(['sync_key' => $syncKey]);
        $row->last_error = mb_substr($error, 0, 5000);
        $row->save();

        return $row;
    }
}

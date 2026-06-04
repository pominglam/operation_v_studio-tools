<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Models\Shopify\ShopifySyncLog;
use Illuminate\Support\Carbon;

final class ShopifyCatalogMirrorFreshnessService
{
    public function maxAgeSeconds(): int
    {
        return max(60, (int) config('shopify.po_prepare_mirror_freshness_seconds', 3600));
    }

    public function isFresh(?int $maxAgeSeconds = null): bool
    {
        $snapshot = $this->snapshot($maxAgeSeconds);

        return $snapshot['mirror_fresh'];
    }

    /**
     * @return array{
     *   mirror_fresh: bool,
     *   max_age_seconds: int,
     *   products_last_completed_at: string|null,
     *   inventory_levels_last_completed_at: string|null
     * }
     */
    public function snapshot(?int $maxAgeSeconds = null): array
    {
        $maxAge = $maxAgeSeconds ?? $this->maxAgeSeconds();
        $productsAt = $this->lastCompletedAt('products');
        $inventoryAt = $this->lastCompletedAt('inventory_levels');
        $cutoff = now()->subSeconds($maxAge);

        $mirrorFresh = $productsAt !== null
            && $inventoryAt !== null
            && $productsAt->greaterThanOrEqualTo($cutoff)
            && $inventoryAt->greaterThanOrEqualTo($cutoff);

        return [
            'mirror_fresh' => $mirrorFresh,
            'max_age_seconds' => $maxAge,
            'products_last_completed_at' => $productsAt?->toISOString(),
            'inventory_levels_last_completed_at' => $inventoryAt?->toISOString(),
        ];
    }

    private function lastCompletedAt(string $syncKey): ?Carbon
    {
        /** @var ShopifySyncLog|null $log */
        $log = ShopifySyncLog::query()
            ->where('sync_key', '=', $syncKey)
            ->where('status', '=', 'completed')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        return $log?->finished_at;
    }
}

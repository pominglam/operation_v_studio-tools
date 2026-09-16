<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Models\Shopify\ShopifySyncLog;
use Illuminate\Support\Carbon;

final class ShopifyCustomerMirrorFreshnessService
{
    public function maxAgeSeconds(): int
    {
        return max(3600, (int) config('shopify.customer_mirror_freshness_seconds', 86400));
    }

    public function isFresh(?int $maxAgeSeconds = null): bool
    {
        $lastCompleted = $this->lastCompletedAt();

        if ($lastCompleted === null) {
            return false;
        }

        $maxAge = $maxAgeSeconds ?? $this->maxAgeSeconds();

        return $lastCompleted->greaterThanOrEqualTo(now()->subSeconds($maxAge));
    }

    public function lastCompletedAt(): ?Carbon
    {
        /** @var ShopifySyncLog|null $log */
        $log = ShopifySyncLog::query()
            ->where('sync_key', '=', 'customers')
            ->where('status', '=', 'completed')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        return $log?->finished_at;
    }
}

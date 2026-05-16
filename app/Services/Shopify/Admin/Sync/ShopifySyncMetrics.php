<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

final class ShopifySyncMetrics
{
    private int $fetched = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $failed = 0;

    public function recordFetch(int $n = 1): void
    {
        $this->fetched += max(0, $n);
    }

    public function recordUpsert(bool $created): void
    {
        if ($created) {
            $this->created++;
        } else {
            $this->updated++;
        }
    }

    public function recordFailure(int $n = 1): void
    {
        $this->failed += max(0, $n);
    }

    /**
     * @return array{fetched:int,created:int,updated:int,failed:int}
     */
    public function toArray(): array
    {
        return [
            'fetched' => $this->fetched,
            'created' => $this->created,
            'updated' => $this->updated,
            'failed' => $this->failed,
        ];
    }
}

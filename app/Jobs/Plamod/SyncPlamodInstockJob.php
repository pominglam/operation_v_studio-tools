<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Services\Plamod\PlamodInstockSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncPlamodInstockJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 10860;

    public int $tries = 1;

    /**
     * @param  array<int, array{name: string, tab: string, category_id: string|null, expected: int}>  $retryFilters
     */
    public function __construct(
        public readonly int $syncLogId,
        public readonly array $retryFilters = [],
    ) {
        $this->onQueue(PlamodInstockSyncService::QUEUE);
    }

    public function handle(PlamodInstockSyncService $sync): void
    {
        $sync->run($this->syncLogId, $this->retryFilters);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderImageService;
use App\Services\Plamod\PlamodPreorderSyncLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DownloadPlamodPreorderImageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public readonly int $syncLogId,
        public readonly string $sku,
    ) {}

    public function handle(
        PlamodPreorderImageService $images,
        PlamodPreorderSyncLogger $logger,
    ): void {
        $success = $images->downloadForSku($this->sku);

        /** @var PlamodPreorderSyncLog|null $log */
        $log = PlamodPreorderSyncLog::query()->find($this->syncLogId);
        if ($log === null) {
            return;
        }

        $log = $logger->incrementImageCount($log, $success);
        $logger->maybeCompleteAfterImages($log);
    }
}

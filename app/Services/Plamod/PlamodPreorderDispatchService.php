<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\SyncPlamodPreordersJob;

final class PlamodPreorderDispatchService
{
    public function __construct(
        private readonly PlamodPreorderSyncLogger $logger,
        private readonly PlamodScraperHealthService $health,
    ) {}

    /**
     * @return array{ok: bool, sync_log_id: int|null, error_message?: string, skipped?: bool}
     */
    public function dispatch(bool $skipIfActive = false): array
    {
        if ($skipIfActive && $this->logger->hasActiveSync()) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'skipped' => true,
                'error_message' => 'A PLAMOD preorders refresh is already running.',
            ];
        }

        $ready = $this->health->assertPreorderCatalogSyncReady();
        if (! $ready['ok']) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'error_message' => (string) ($ready['error_message'] ?? 'Plamod scraper is not ready.'),
            ];
        }

        $log = $this->logger->queue();
        SyncPlamodPreordersJob::dispatch((int) $log->id);

        return [
            'ok' => true,
            'sync_log_id' => (int) $log->id,
        ];
    }
}

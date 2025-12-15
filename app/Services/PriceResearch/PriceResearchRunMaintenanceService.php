<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\Models\PriceResearchRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PriceResearchRunMaintenanceService
{
    public function __construct(
        private readonly PriceResearchRunRepository $runs,
    ) {
    }

    /**
     * Reset a stuck run (queued/running) to failed so the UI is not blocked.
     * If $uuid is null, resets the latest run if it is active.
     *
     * @throws ModelNotFoundException
     */
    public function reset(?string $uuid = null): ?PriceResearchRun
    {
        $run = $uuid !== null ? $this->runs->findByUuidOrFail($uuid) : $this->runs->latest();
        if ($run === null) {
            return null;
        }

        if (! in_array($run->status, ['queued', 'running'], true)) {
            return null;
        }

        $run->status = 'failed';
        $run->error_message = 'Manually reset from Maintenance.';
        $run->finished_at = now();

        return $this->runs->save($run);
    }
}



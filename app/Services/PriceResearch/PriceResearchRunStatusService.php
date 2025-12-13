<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\Models\PriceResearchRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PriceResearchRunStatusService
{
    public function __construct(
        private readonly PriceResearchRunRepository $runs,
        private readonly PriceResearchService $research,
    ) {
    }

    public function findByUuidOrFail(string $uuid): PriceResearchRun
    {
        $run = $this->runs->findByUuidOrFail($uuid);

        return $this->autoStartIfStuck($run);
    }

    public function latest(): ?PriceResearchRun
    {
        $run = $this->runs->latest();
        if ($run === null) {
            return null;
        }

        return $this->autoStartIfStuck($run);
    }

    private function autoStartIfStuck(PriceResearchRun $run): PriceResearchRun
    {
        if (! app()->environment('local')) {
            return $run;
        }

        if (! (bool) config('price_research.local_inline_queue_fallback', true)) {
            return $run;
        }

        if ($run->status !== 'queued') {
            return $run;
        }

        // In sync mode, the controller runs inline already; nothing to do.
        if (config('queue.default') === 'sync') {
            return $run;
        }

        $stuckSeconds = max(0, (int) config('price_research.local_queue_stuck_seconds', 3));
        if ($stuckSeconds > 0 && $run->created_at !== null && $run->created_at->diffInSeconds(now()) < $stuckSeconds) {
            return $run;
        }

        // Try to "claim" the run so multiple pollers don't start it twice.
        $claimed = PriceResearchRun::query()
            ->where('uuid', $run->uuid)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

        if ($claimed !== 1) {
            return $this->runs->findByUuidOrFail($run->uuid);
        }

        try {
            // NOTE: UI currently only runs "all products", so we don't persist a subset of IDs here.
            $this->research->run(null, (bool) $run->force, $run->uuid);
        } catch (Throwable $e) {
            DB::transaction(function () use ($run, $e): void {
                $fresh = $this->runs->findByUuidOrFail($run->uuid);
                $fresh->status = 'failed';
                $fresh->error_message = $e->getMessage();
                $fresh->finished_at = now();
                $this->runs->save($fresh);
            });
        }

        return $this->runs->findByUuidOrFail($run->uuid);
    }
}



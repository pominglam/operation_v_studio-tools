<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodRestockCartRun;
use App\Services\Products\Http\PlamodScraper;

final class PlamodRestockCartStatusService
{
    public function __construct(
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        /** @var PlamodRestockCartRun|null $latest */
        $latest = PlamodRestockCartRun::query()->orderByDesc('id')->first();

        if ($latest === null) {
            return [
                'status' => 'never',
                'cart_run_id' => null,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'counts' => [],
                'report' => null,
                'summary' => null,
                'all_verified' => null,
                'error_summary' => null,
            ];
        }

        $counts = $latest->counts_json ?? [];

        if (in_array((string) $latest->status, ['queued', 'running'], true)) {
            $progress = $this->scraper->restockCartProgress();
            if (($progress['active'] ?? false) === true) {
                $counts = array_merge($counts, $progress);
            } elseif (($counts['phase'] ?? '') === '') {
                $counts['phase'] = (string) $latest->status === 'queued' ? 'queued' : 'adding';
            }
        }

        return [
            'status' => (string) $latest->status,
            'cart_run_id' => (int) $latest->id,
            'started_at' => $latest->started_at?->toIso8601String(),
            'finished_at' => $latest->finished_at?->toIso8601String(),
            'duration_ms' => $latest->duration_ms,
            'counts' => $counts,
            'report' => $counts['report'] ?? null,
            'summary' => $counts['summary'] ?? ($counts['report']['summary'] ?? null),
            'all_verified' => $counts['all_verified'] ?? ($counts['report']['summary']['all_verified'] ?? null),
            'error_summary' => $latest->error_summary,
        ];
    }
}

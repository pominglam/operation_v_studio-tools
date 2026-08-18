<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodRestockCartRun;

final class PlamodRestockCartRunLogger
{
    public function queue(): PlamodRestockCartRun
    {
        return PlamodRestockCartRun::query()->create([
            'status' => 'queued',
            'counts_json' => [],
        ]);
    }

    public function hasActiveRun(): bool
    {
        return PlamodRestockCartRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    public function latestLineErrorMessages(): array
    {
        $run = PlamodRestockCartRun::query()
            ->where('status', '=', 'completed')
            ->orderByDesc('id')
            ->first();
        $counts = $run?->counts_json ?? [];
        $lines = $counts['report']['lines'] ?? [];
        if (! is_array($lines)) {
            return [];
        }

        $messages = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $sku = trim((string) ($line['sku'] ?? ''));
            $message = trim((string) ($line['error_message'] ?? ''));
            if ($sku !== '' && $message !== '') {
                $messages[$sku] = $message;
            }
        }

        return $messages;
    }

    public function markRunning(PlamodRestockCartRun $run): PlamodRestockCartRun
    {
        $run->forceFill([
            'status' => 'running',
            'started_at' => now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public function progress(PlamodRestockCartRun $run, array $counts): PlamodRestockCartRun
    {
        $run->forceFill([
            'counts_json' => array_merge($run->counts_json ?? [], $counts),
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public function complete(PlamodRestockCartRun $run, array $counts = []): PlamodRestockCartRun
    {
        $started = $run->started_at ?? now();
        $run->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
            'duration_ms' => (int) max(0, $started->diffInMilliseconds(now())),
            'counts_json' => array_merge($run->counts_json ?? [], $counts),
        ])->save();

        return $run->refresh();
    }

    public function fail(PlamodRestockCartRun $run, string $error, array $counts = []): PlamodRestockCartRun
    {
        $started = $run->started_at ?? now();
        $run->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
            'duration_ms' => (int) max(0, $started->diffInMilliseconds(now())),
            'error_summary' => mb_substr($error, 0, 5000),
            'counts_json' => array_merge($run->counts_json ?? [], $counts),
        ])->save();

        return $run->refresh();
    }
}

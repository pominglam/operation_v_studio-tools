<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class PlamodPreorderSyncFailureRecorder
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(int $syncLogId, string $phase, array $context): void
    {
        $event = [
            'sync_log_id' => $syncLogId,
            'phase' => $phase,
            'recorded_at' => now()->toIso8601String(),
            ...$context,
        ];

        $this->events[] = $event;

        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            Storage::disk('local')->append($this->logPath($syncLogId), $line."\n");
        }

        Log::warning('Plamod preorder sync step failed', $event);
    }

    /**
     * @return array{
     *   failure_log_path: string,
     *   failure_event_count: int,
     *   failure_summary: array<int, array{error_kind: string, error_message: string, count: int}>
     * }
     */
    public function finalize(int $syncLogId): array
    {
        $summary = $this->buildSummary();

        return [
            'failure_log_path' => $this->logPath($syncLogId),
            'failure_event_count' => count($this->events),
            'failure_summary' => $summary,
        ];
    }

    /**
     * @return array<int, array{error_kind: string, error_message: string, count: int}>
     */
    private function buildSummary(): array
    {
        /** @var array<string, array{error_kind: string, error_message: string, count: int}> $buckets */
        $buckets = [];

        foreach ($this->events as $event) {
            $kind = (string) ($event['error_kind'] ?? 'other');
            $message = mb_substr((string) ($event['error_message'] ?? 'Unknown error'), 0, 240);
            $key = $kind.'|'.$message;

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'error_kind' => $kind,
                    'error_message' => $message,
                    'count' => 0,
                ];
            }

            $buckets[$key]['count']++;
        }

        $summary = array_values($buckets);
        usort($summary, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $summary;
    }

    private function logPath(int $syncLogId): string
    {
        return 'plamod/preorder_sync_logs/sync-'.$syncLogId.'-failures.jsonl';
    }
}

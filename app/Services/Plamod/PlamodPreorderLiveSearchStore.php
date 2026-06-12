<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PlamodPreorderLiveSearchStore
{
    private const int TTL_SECONDS = 3600;

    /**
     * @param  array<int, string>  $lines
     */
    public function create(array $lines): string
    {
        $jobId = (string) Str::uuid();
        $this->put($jobId, [
            'status' => 'queued',
            'lines' => array_values($lines),
            'plamod_only' => [],
            'not_found' => [],
            'rows' => [],
            'error_summary' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);

        return $jobId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $jobId): ?array
    {
        /** @var array<string, mixed>|null $payload */
        $payload = Cache::get($this->key($jobId));

        return is_array($payload) ? $payload : null;
    }

    public function markRunning(string $jobId): void
    {
        $payload = $this->get($jobId);
        if ($payload === null) {
            return;
        }

        $payload['status'] = 'running';
        $this->put($jobId, $payload);
    }

    /**
     * @param  array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>  $plamodOnly
     * @param  array<int, string>  $notFound
     */
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function complete(string $jobId, array $plamodOnly, array $notFound, array $rows = []): void
    {
        $payload = $this->get($jobId);
        if ($payload === null) {
            return;
        }

        $payload['status'] = 'completed';
        $payload['plamod_only'] = $plamodOnly;
        $payload['not_found'] = $notFound;
        $payload['rows'] = $rows;
        $payload['finished_at'] = now()->toIso8601String();
        $payload['error_summary'] = null;
        $this->put($jobId, $payload);
    }

    public function fail(string $jobId, string $message): void
    {
        $payload = $this->get($jobId);
        if ($payload === null) {
            return;
        }

        $payload['status'] = 'failed';
        $payload['error_summary'] = $message;
        $payload['finished_at'] = now()->toIso8601String();
        $this->put($jobId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(string $jobId, array $payload): void
    {
        Cache::put($this->key($jobId), $payload, self::TTL_SECONDS);
    }

    private function key(string $jobId): string
    {
        return 'plamod_preorder_live_search:'.$jobId;
    }
}

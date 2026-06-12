<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PlamodPreorderManufacturerFilterDiscoverStore
{
    private const int TTL_SECONDS = 3600;

    public function create(int $manufacturerId = 1): string
    {
        $jobId = (string) Str::uuid();
        $this->put($jobId, [
            'status' => 'queued',
            'manufacturer_id' => $manufacturerId,
            'discover' => null,
            'error_message' => null,
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
     * @param  array<string, mixed>  $discover
     */
    public function complete(string $jobId, array $discover): void
    {
        $payload = $this->get($jobId);
        if ($payload === null) {
            return;
        }

        $payload['status'] = 'completed';
        $payload['discover'] = $discover;
        $payload['error_message'] = null;
        $payload['finished_at'] = now()->toIso8601String();
        $this->put($jobId, $payload);
    }

    public function fail(string $jobId, string $message): void
    {
        $payload = $this->get($jobId);
        if ($payload === null) {
            return;
        }

        $payload['status'] = 'failed';
        $payload['error_message'] = $message;
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
        return 'plamod_preorder_mfr_filter_discover:'.$jobId;
    }
}

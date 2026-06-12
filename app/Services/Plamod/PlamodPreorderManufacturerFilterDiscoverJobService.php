<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\DiscoverPlamodPreorderManufacturerFiltersJob;

final class PlamodPreorderManufacturerFilterDiscoverJobService
{
    public function __construct(
        private readonly PlamodPreorderManufacturerFilterDiscoverStore $store,
        private readonly PlamodPreorderManufacturerFilterService $filters,
    ) {}

    /**
     * @return array{status: string, job_id: string}
     */
    public function start(int $manufacturerId = 1): array
    {
        $jobId = $this->store->create($manufacturerId);
        DiscoverPlamodPreorderManufacturerFiltersJob::dispatch($jobId, $manufacturerId);

        return [
            'status' => 'queued',
            'job_id' => $jobId,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     job_id?: string,
     *     ok?: bool,
     *     discover?: array<string, mixed>|null,
     *     filters?: array<string, mixed>|null,
     *     error_message?: string|null
     * }
     */
    public function status(string $jobId): array
    {
        $payload = $this->store->get($jobId);
        if ($payload === null) {
            return [
                'status' => 'missing',
                'error_message' => 'Discover job not found or expired.',
            ];
        }

        $status = (string) ($payload['status'] ?? 'queued');
        if ($status === 'completed') {
            $manufacturerId = (int) ($payload['manufacturer_id'] ?? 1);

            return [
                'status' => 'completed',
                'job_id' => $jobId,
                'ok' => true,
                'discover' => is_array($payload['discover'] ?? null) ? $payload['discover'] : null,
                'filters' => $this->filters->listGrouped($manufacturerId),
            ];
        }

        if ($status === 'failed') {
            return [
                'status' => 'failed',
                'job_id' => $jobId,
                'ok' => false,
                'error_message' => is_string($payload['error_message'] ?? null) ? $payload['error_message'] : 'Discover failed',
            ];
        }

        return [
            'status' => $status,
            'job_id' => $jobId,
        ];
    }
}

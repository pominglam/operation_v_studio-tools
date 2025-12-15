<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PriceResearch\PriceResearchRunStatusService;
use Illuminate\Http\JsonResponse;

final class PriceResearchRunStatusController extends Controller
{
    public function __construct(
        private readonly PriceResearchRunStatusService $status,
    ) {}

    public function show(string $id): JsonResponse
    {
        $run = $this->status->findByUuidOrFail($id);

        return response()->json([
            'data' => $this->toArray($run),
        ]);
    }

    public function latest(): JsonResponse
    {
        $run = $this->status->latest();
        if ($run === null) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => $this->toArray($run),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(\App\Models\PriceResearchRun $run): array
    {
        return [
            'id' => $run->uuid,
            'status' => $run->status,
            'force' => $run->force,
            'ttl_days' => $run->ttl_days,
            'total_products' => $run->total_products,
            'processed_products' => $run->processed_products,
            'refreshed_products' => $run->refreshed_products,
            'skipped_fresh_products' => $run->skipped_fresh_products,
            'total_sites' => $run->total_sites,
            'processed_sites' => $run->processed_sites,
            'quotes_written' => $run->quotes_written,
            'started_at' => optional($run->started_at)->toISOString(),
            'finished_at' => optional($run->finished_at)->toISOString(),
            'error_message' => $run->error_message,
        ];
    }
}

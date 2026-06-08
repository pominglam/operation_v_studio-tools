<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodPreordersSearchLinesRequest;
use App\Services\Plamod\PlamodPreorderSearchLinesService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersSearchLinesController extends Controller
{
    public function __invoke(
        PlamodPreordersSearchLinesRequest $request,
        PlamodPreorderSearchLinesService $search,
    ): JsonResponse {
        $validated = $request->validated();
        /** @var array<int, string> $lines */
        $lines = $validated['lines'];
        $phase = (string) ($validated['phase'] ?? 'all');

        $data = match ($phase) {
            'snapshot' => $search->searchSnapshot($lines),
            'live' => $search->searchLive($lines),
            default => $search->search($lines),
        };

        return response()->json(['data' => $data]);
    }
}

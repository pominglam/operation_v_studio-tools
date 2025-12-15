<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPriceResearchRunRequest;
use App\Services\PriceResearch\PriceResearchRunMaintenanceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class PriceResearchRunMaintenanceController extends Controller
{
    public function __construct(
        private readonly PriceResearchRunMaintenanceService $maintenance,
    ) {
    }

    public function __invoke(ResetPriceResearchRunRequest $request): JsonResponse
    {
        /** @var string|null $id */
        $id = $request->validated('id');

        try {
            $run = $this->maintenance->reset($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Run not found.',
            ], 404);
        }

        if ($run === null) {
            return response()->json([
                'message' => 'No queued/running run to reset.',
            ], 409);
        }

        return response()->json([
            'message' => 'Run reset.',
            'data' => [
                'id' => $run->uuid,
                'status' => $run->status,
                'finished_at' => optional($run->finished_at)->toISOString(),
                'error_message' => $run->error_message,
            ],
        ]);
    }
}



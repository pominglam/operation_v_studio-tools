<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PriceResearchQuoteReportResource;
use App\Services\PriceResearch\PriceResearchQuoteReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class PriceResearchQuoteReportHandledController extends Controller
{
    public function __construct(
        private readonly PriceResearchQuoteReportService $reports,
    ) {}

    public function __invoke(int $id): JsonResponse
    {
        try {
            $report = $this->reports->markHandled($id);

            return PriceResearchQuoteReportResource::make($report)->response();
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Report not found.'], 404);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PriceResearch\PriceResearchQuoteMaintenanceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class PriceResearchQuoteMaintenanceController extends Controller
{
    public function __construct(
        private readonly PriceResearchQuoteMaintenanceService $quotes,
    ) {
    }

    public function __invoke(string $productId, string $siteKey): JsonResponse
    {
        try {
            $deleted = $this->quotes->deleteQuote($productId, $siteKey);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if (! $deleted) {
            return response()->json([
                'message' => 'Quote not found.',
            ], 404);
        }

        return response()->json([
            'deleted' => true,
        ]);
    }
}



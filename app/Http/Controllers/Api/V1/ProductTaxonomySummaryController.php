<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductTaxonomyReviewQueryService;
use Illuminate\Http\JsonResponse;

final class ProductTaxonomySummaryController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyReviewQueryService $query,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->query->summary(),
        ]);
    }
}

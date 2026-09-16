<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyResearchSelectedRequest;
use App\Services\Products\ProductTaxonomyResearchService;
use Illuminate\Http\JsonResponse;

final class ProductTaxonomyResearchSelectedController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyResearchService $research,
    ) {}

    public function __invoke(ProductTaxonomyResearchSelectedRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('verification_ids');
        /** @var array<int, string>|null $fields */
        $fields = $request->validated('fields');
        $result = $this->research->researchSelected($ids, $fields);

        return response()->json([
            'data' => [
                'researched' => $result->researched,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ],
        ]);
    }
}

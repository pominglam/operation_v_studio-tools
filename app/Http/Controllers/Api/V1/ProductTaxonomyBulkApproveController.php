<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyBulkApproveRequest;
use App\Services\Products\ProductTaxonomyBulkApprovalService;
use Illuminate\Http\JsonResponse;

final class ProductTaxonomyBulkApproveController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyBulkApprovalService $approval,
    ) {}

    public function __invoke(ProductTaxonomyBulkApproveRequest $request): JsonResponse
    {
        $result = $this->approval->approveByFilters(
            $request->reviewFilters(),
            (string) $request->validated('operator'),
            (bool) ($request->validated('exclude_test_skus') ?? true),
            (bool) ($request->validated('require_kit_manufacturer') ?? true),
            false,
            $request->validated('notes'),
        );

        return response()->json([
            'data' => [
                'approved' => $result->approved,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ],
        ]);
    }
}

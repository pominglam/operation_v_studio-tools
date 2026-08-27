<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyBulkUpdateRequest;
use App\Services\Products\ProductTaxonomyBulkUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductTaxonomyBulkUpdateController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyBulkUpdateService $updates,
    ) {}

    public function __invoke(ProductTaxonomyBulkUpdateRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('verification_ids');
        $result = $this->updates->updateSelected(
            $ids,
            $request->values(),
            (string) $request->validated('operator'),
            $request->validated('notes'),
        );

        return response()->json([
            'data' => [
                'updated' => $result->approved,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ],
        ]);
    }
}

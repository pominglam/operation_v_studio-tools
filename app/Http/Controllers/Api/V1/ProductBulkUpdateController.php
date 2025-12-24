<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkUpdateProductsRequest;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\Products\ProductBulkUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductBulkUpdateController extends Controller
{
    public function __construct(
        private readonly ProductBulkUpdateService $updater,
    ) {}

    public function __invoke(BulkUpdateProductsRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');

        /** @var array{
         *   sku?: string,
         *   barcode?: string|null,
         *   description?: string,
         *   type?: string|null,
         *   vendor?: string|null,
         *   price?: string|int|float|null,
         *   order?: int|null,
         *   filled?: int|null,
         *   extended?: string|int|float|null
         * } $changes
         */
        $changes = $request->validated('changes');

        try {
            $updated = $this->updater->updateByUuids($ids, $changes);

            return response()->json([
                'updated' => $updated,
            ]);
        } catch (DuplicateSkuException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}




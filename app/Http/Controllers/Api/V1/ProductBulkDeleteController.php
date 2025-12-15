<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkDeleteProductsRequest;
use App\Services\Products\ProductBulkDeleteService;
use Illuminate\Http\JsonResponse;

final class ProductBulkDeleteController extends Controller
{
    public function __construct(
        private readonly ProductBulkDeleteService $deleter,
    ) {}

    public function __invoke(BulkDeleteProductsRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');

        $deleted = $this->deleter->deleteByUuids($ids);

        return response()->json([
            'deleted' => $deleted,
        ]);
    }
}

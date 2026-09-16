<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderBulkDeleteRequest;
use App\Services\StorePreorders\StorePreorderDeleteService;
use Illuminate\Http\JsonResponse;

final class StorePreorderBulkDeleteController extends Controller
{
    public function __construct(
        private readonly StorePreorderDeleteService $delete,
    ) {}

    public function __invoke(StorePreorderBulkDeleteRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');
        $result = $this->delete->deleteMany(array_values($ids));

        return response()->json([
            'deleted' => $result->deleted,
            'products_deleted' => $result->productsDeleted,
        ]);
    }
}

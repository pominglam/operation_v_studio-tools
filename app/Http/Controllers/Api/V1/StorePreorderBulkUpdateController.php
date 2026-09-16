<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderBulkUpdateRequest;
use App\Services\StorePreorders\Exceptions\StorePreorderUpdateException;
use App\Services\StorePreorders\StorePreorderBulkUpdateService;
use Illuminate\Http\JsonResponse;

final class StorePreorderBulkUpdateController extends Controller
{
    public function __construct(
        private readonly StorePreorderBulkUpdateService $update,
    ) {}

    public function __invoke(StorePreorderBulkUpdateRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');
        /** @var array{cap_qty?: int|null, deposit_percent?: string|int|float, selling_price?: string|int|float, window_ends_on?: string} $changes */
        $changes = $request->validated('changes');

        try {
            $result = $this->update->update(array_values($ids), $changes);
        } catch (StorePreorderUpdateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'updated' => $result->updated,
            'skipped' => $result->skipped,
        ]);
    }
}

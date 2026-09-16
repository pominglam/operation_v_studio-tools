<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StorePreorders\StorePreorderDeleteService;
use Illuminate\Http\JsonResponse;

final class StorePreorderDeleteController extends Controller
{
    public function __construct(
        private readonly StorePreorderDeleteService $delete,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $result = $this->delete->delete($id);

        return response()->json([
            'ok' => true,
            'product_deleted' => $result['product_deleted'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductReplenishmentService;
use Illuminate\Http\JsonResponse;

final class ProductReplenishmentPreviewController extends Controller
{
    public function __invoke(ProductReplenishmentService $service): JsonResponse
    {
        $rows = $service->previewRows();

        return response()->json([
            'ok' => true,
            'data' => $rows->values()->all(),
            'meta' => [
                'count' => $rows->count(),
                'total_suggested_order_qty' => (int) $rows->sum('suggested_order_qty'),
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductTypeBackfillService;
use Illuminate\Http\JsonResponse;

final class ProductTypeBackfillController extends Controller
{
    public function __construct(
        private readonly ProductTypeBackfillService $backfill,
    ) {}

    public function __invoke(): JsonResponse
    {
        $updated = $this->backfill->backfillMissingTypes();

        return response()->json([
            'updated' => $updated,
        ]);
    }
}



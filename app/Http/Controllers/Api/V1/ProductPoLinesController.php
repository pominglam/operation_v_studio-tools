<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductPoLinesQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductPoLinesController extends Controller
{
    public function __construct(
        private readonly ProductPoLinesQueryService $poLines,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);

        return response()->json([
            'lines' => $this->poLines->listForProduct($id, $limit),
        ]);
    }
}


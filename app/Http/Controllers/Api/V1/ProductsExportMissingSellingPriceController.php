<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsExportRequest;
use App\Services\Products\ProductExportService;
use Illuminate\Http\JsonResponse;

final class ProductsExportMissingSellingPriceController extends Controller
{
    public function __construct(
        private readonly ProductExportService $exports,
    ) {}

    public function __invoke(ProductsExportRequest $request): JsonResponse
    {
        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        $products = $this->exports->listMissingSellingPriceForExport($sortBy, $sortDir);

        return response()->json([
            'data' => $products->map(static fn ($p): array => [
                'id' => $p->uuid,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'description' => $p->description,
            ])->values(),
        ]);
    }
}



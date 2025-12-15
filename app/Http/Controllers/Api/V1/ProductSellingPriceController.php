<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductSellingPriceUpsertRequest;
use App\Services\Products\ProductSellingPriceService;
use Illuminate\Http\JsonResponse;

final class ProductSellingPriceController extends Controller
{
    public function __construct(
        private readonly ProductSellingPriceService $sellingPrices,
    ) {}

    public function __invoke(ProductSellingPriceUpsertRequest $request, string $id): JsonResponse
    {
        /** @var float|int|string|null $raw */
        $raw = $request->validated('selling_price');
        $sellingPrice = $raw === null ? null : number_format((float) $raw, 2, '.', '');
        $currency = (string) ($request->validated('currency') ?? 'CAD');

        $row = $this->sellingPrices->upsertForProductUuid($id, $sellingPrice, $currency);

        return response()->json([
            'data' => [
                'product_id' => $id,
                'selling_price' => $row->selling_price,
                'currency' => $row->currency,
            ],
        ]);
    }
}

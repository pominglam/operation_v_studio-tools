<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifyCustomerSuggestRequest;
use App\Services\Shopify\Admin\Customers\ShopifyCustomerSuggestService;
use Illuminate\Http\JsonResponse;

final class ShopifyCustomerSuggestController extends Controller
{
    public function __construct(
        private readonly ShopifyCustomerSuggestService $suggestions,
    ) {}

    public function __invoke(ShopifyCustomerSuggestRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 8;

        try {
            $items = $this->suggestions->suggest((string) $validated['q'], $limit);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $items]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomAsiaOrderProductNameSuggestRequest;
use App\Services\CustomOrders\CustomAsiaOrderProductNameSuggestService;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderProductNameSuggestController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderProductNameSuggestService $suggestions,
    ) {}

    public function __invoke(CustomAsiaOrderProductNameSuggestRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 8;

        $items = $this->suggestions->suggest((string) $validated['q'], $limit);

        return response()->json([
            'data' => array_map(static fn ($item) => $item->toArray(), $items),
        ]);
    }
}

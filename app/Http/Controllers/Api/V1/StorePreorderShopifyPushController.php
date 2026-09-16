<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderShopifyPushRequest;
use App\Services\StorePreorders\StorePreorderShopifyShelfPushService;
use Illuminate\Http\JsonResponse;

final class StorePreorderShopifyPushController extends Controller
{
    public function __construct(
        private readonly StorePreorderShopifyShelfPushService $push,
    ) {}

    public function __invoke(StorePreorderShopifyPushRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids') ?? [];
        $result = $this->push->pushOpen(array_values($ids));

        return response()->json([
            'pushed' => $result->pushed,
            'failed' => $result->failed,
            'errors' => $result->errors,
        ]);
    }
}

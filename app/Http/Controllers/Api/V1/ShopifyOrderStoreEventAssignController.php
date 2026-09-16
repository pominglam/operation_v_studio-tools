<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifyOrderStoreEventAssignRequest;
use App\Services\StoreEvents\Exceptions\StoreEventNotFoundException;
use App\Services\StoreEvents\StoreEventOrderAssignmentService;
use Illuminate\Http\JsonResponse;

final class ShopifyOrderStoreEventAssignController extends Controller
{
    public function __construct(
        private readonly StoreEventOrderAssignmentService $assignment,
    ) {}

    public function __invoke(ShopifyOrderStoreEventAssignRequest $request): JsonResponse
    {
        try {
            $result = $this->assignment->assignToEvent($request->orderIds(), $request->eventUuid());
        } catch (StoreEventNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['updated' => $result->updated]);
    }
}

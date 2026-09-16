<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEventOrdersAssignRequest;
use App\Services\StoreEvents\Exceptions\StoreEventNotFoundException;
use App\Services\StoreEvents\StoreEventOrderAssignmentService;
use Illuminate\Http\JsonResponse;

final class StoreEventOrdersAssignController extends Controller
{
    public function __construct(
        private readonly StoreEventOrderAssignmentService $assignment,
    ) {}

    public function __invoke(string $eventId, StoreEventOrdersAssignRequest $request): JsonResponse
    {
        try {
            $result = $this->assignment->setIncluded(
                $eventId,
                $request->orderIds(),
                $request->boolean('included'),
            );
        } catch (StoreEventNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['updated' => $result->updated]);
    }
}

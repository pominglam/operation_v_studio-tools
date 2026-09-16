<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEventWriteRequest;
use App\Http\Resources\Api\V1\StoreEventResource;
use App\Services\StoreEvents\Exceptions\StoreEventNotFoundException;
use App\Services\StoreEvents\StoreEventService;
use Illuminate\Http\JsonResponse;

final class StoreEventUpdateController extends Controller
{
    public function __construct(
        private readonly StoreEventService $events,
    ) {}

    public function __invoke(string $eventId, StoreEventWriteRequest $request): JsonResponse|StoreEventResource
    {
        try {
            return new StoreEventResource($this->events->update($eventId, $request->toWriteData()));
        } catch (StoreEventNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }
}

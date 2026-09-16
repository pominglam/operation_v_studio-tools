<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StoreEvents\Exceptions\StoreEventNotFoundException;
use App\Services\StoreEvents\StoreEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class StoreEventDeleteController extends Controller
{
    public function __construct(
        private readonly StoreEventService $events,
    ) {}

    public function __invoke(string $eventId): JsonResponse|Response
    {
        try {
            $this->events->delete($eventId);
        } catch (StoreEventNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->noContent();
    }
}

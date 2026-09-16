<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEventWriteRequest;
use App\Http\Resources\Api\V1\StoreEventResource;
use App\Services\StoreEvents\StoreEventService;
use Illuminate\Http\JsonResponse;

final class StoreEventStoreController extends Controller
{
    public function __construct(
        private readonly StoreEventService $events,
    ) {}

    public function __invoke(StoreEventWriteRequest $request): JsonResponse
    {
        return (new StoreEventResource($this->events->create($request->toWriteData())))
            ->response()
            ->setStatusCode(201);
    }
}

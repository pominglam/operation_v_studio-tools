<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEventIndexRequest;
use App\Http\Resources\Api\V1\StoreEventCalendarResource;
use App\Services\StoreEvents\StoreEventCalendarAssembler;
use App\Services\StoreEvents\StoreEventService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StoreEventIndexController extends Controller
{
    public function __construct(
        private readonly StoreEventService $events,
        private readonly StoreEventCalendarAssembler $calendar,
    ) {}

    public function __invoke(StoreEventIndexRequest $request): AnonymousResourceCollection
    {
        $search = $request->validated('search');

        return StoreEventCalendarResource::collection(
            $this->calendar->assemble($this->events->list(is_string($search) ? $search : null)),
        );
    }
}

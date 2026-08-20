<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShipmentTrackingResolutionRequest;
use App\Http\Resources\Api\V1\ShipmentTrackingResolutionResource;
use App\Services\ShipmentTracking\ShipmentTrackingResolutionDispatchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ShipmentTrackingResolutionController extends Controller
{
    public function __construct(
        private readonly ShipmentTrackingResolutionDispatchService $dispatcher,
    ) {}

    public function __invoke(
        ShipmentTrackingResolutionRequest $request,
    ): AnonymousResourceCollection {
        /** @var array{tracking_numbers: array<int, string>} $validated */
        $validated = $request->validated();

        return ShipmentTrackingResolutionResource::collection(
            $this->dispatcher->dispatch($validated['tracking_numbers']),
        );
    }
}

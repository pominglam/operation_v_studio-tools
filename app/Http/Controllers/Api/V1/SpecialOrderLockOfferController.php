<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LockSpecialOrderOfferRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderLockOfferService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderLockOfferController extends Controller
{
    public function __construct(
        private readonly SpecialOrderLockOfferService $lockOffer,
    ) {}

    public function __invoke(string $id, LockSpecialOrderOfferRequest $request): SpecialOrderResource|JsonResponse
    {
        try {
            return SpecialOrderResource::make($this->lockOffer->lock($id, $request->validated()));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}

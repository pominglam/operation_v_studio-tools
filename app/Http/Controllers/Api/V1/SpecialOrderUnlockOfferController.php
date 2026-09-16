<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderLockOfferService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderUnlockOfferController extends Controller
{
    public function __construct(
        private readonly SpecialOrderLockOfferService $lockOffer,
    ) {}

    public function __invoke(string $id): SpecialOrderResource|JsonResponse
    {
        try {
            return SpecialOrderResource::make($this->lockOffer->unlock($id));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LockCustomAsiaOrderOfferRequest;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderLockOfferService;

final class CustomAsiaOrderLockOfferController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderLockOfferService $lockOffer,
    ) {}

    public function __invoke(string $id, LockCustomAsiaOrderOfferRequest $request): CustomAsiaOrderResource
    {
        return CustomAsiaOrderResource::make($this->lockOffer->lock($id, $request->validated()));
    }
}

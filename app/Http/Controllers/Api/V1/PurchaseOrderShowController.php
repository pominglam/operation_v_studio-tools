<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\PurchaseOrderQueryService;

final class PurchaseOrderShowController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrders,
    ) {}

    public function __invoke(string $id): PurchaseOrderResource
    {
        return PurchaseOrderResource::make(
            $this->purchaseOrders->findByUuidOrFail($id),
        );
    }
}

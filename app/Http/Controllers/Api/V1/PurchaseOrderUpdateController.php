<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderUpdateRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\PurchaseOrderUpdateService;

final class PurchaseOrderUpdateController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderUpdateService $updater,
    ) {}

    public function __invoke(PurchaseOrderUpdateRequest $request, string $id): PurchaseOrderResource
    {
        /** @var array<string, mixed> $changes */
        $changes = $request->validated();

        return PurchaseOrderResource::make(
            $this->updater->update($id, $changes),
        );
    }
}



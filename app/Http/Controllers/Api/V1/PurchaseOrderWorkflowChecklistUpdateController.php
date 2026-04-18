<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderWorkflowChecklistUpdateRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowChecklistService;

final class PurchaseOrderWorkflowChecklistUpdateController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderWorkflowChecklistService $checklist,
    ) {}

    public function __invoke(PurchaseOrderWorkflowChecklistUpdateRequest $request, string $id): PurchaseOrderResource
    {
        /** @var array<string, mixed> $changes */
        $changes = $request->validated();

        return PurchaseOrderResource::make(
            $this->checklist->update($id, $changes),
        );
    }
}

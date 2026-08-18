<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<PurchaseOrderCombinedPaymentPreview> */
final class PurchaseOrderCombinedPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

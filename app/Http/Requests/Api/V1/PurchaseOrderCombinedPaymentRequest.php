<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderCombinedPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_order_ids' => ['required', 'array', 'min:2', 'max:50'],
            'purchase_order_ids.*' => [
                'required',
                'uuid',
                'distinct',
                'exists:purchase_orders,uuid',
            ],
            'total_paid_cad' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'includes_shipping' => ['required', 'boolean'],
            'product_paid_cad' => [
                'nullable',
                'required_with:shipping_paid_cad',
                'numeric',
                'gt:0',
                'decimal:0,2',
            ],
            'shipping_paid_cad' => [
                'nullable',
                'required_with:product_paid_cad',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],
            'allocations' => ['sometimes', 'array', 'min:2', 'max:50'],
            'allocations.*.purchase_order_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.product_total_cad' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'allocations.*.shipping_total_cad' => [
                'nullable',
                'required_if:includes_shipping,true',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],
        ];
    }
}

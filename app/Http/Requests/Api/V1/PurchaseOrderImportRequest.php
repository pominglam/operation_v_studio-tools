<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
            'vendor' => ['required', 'string', 'max:128'],
            'purchase_order_uuid' => ['sometimes', 'uuid'],
            'ordered_date' => ['sometimes', 'nullable', 'date'],
            'shipped_date' => ['sometimes', 'nullable', 'date'],
            'received_date' => ['sometimes', 'nullable', 'date'],
            'fully_on_shelves_date' => ['sometimes', 'nullable', 'date'],
            'shipping_total' => ['sometimes', 'nullable', 'numeric'],
            'product_total' => ['sometimes', 'nullable', 'numeric'],
            'surcharge_total' => ['sometimes', 'nullable', 'numeric'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}



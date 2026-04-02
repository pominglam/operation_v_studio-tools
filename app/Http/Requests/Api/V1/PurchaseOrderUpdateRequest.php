<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vendor' => ['sometimes', 'string', 'max:128'],
            'ordered_date' => ['nullable', 'date'],
            'shipped_date' => ['nullable', 'date'],
            'estimated_arrival_date' => ['nullable', 'date'],
            'received_date' => ['nullable', 'date'],
            'fully_on_shelves_date' => ['nullable', 'date'],
            'shipping_total' => ['nullable', 'numeric'],
            'surcharge_total' => ['nullable', 'numeric'],
            'product_total' => ['nullable', 'numeric'],
            'vendor_currency_code' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'vendor_product_total' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'is_done' => ['sometimes', 'boolean'],
        ];
    }
}



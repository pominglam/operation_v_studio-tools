<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'import_mode' => ['sometimes', 'string', Rule::in(['replace', 'append'])],
            'ordered_date' => ['sometimes', 'nullable', 'date'],
            'shipped_date' => ['sometimes', 'nullable', 'date'],
            'estimated_arrival_date' => ['sometimes', 'nullable', 'date'],
            'received_date' => ['sometimes', 'nullable', 'date'],
            'fully_on_shelves_date' => ['sometimes', 'nullable', 'date'],
            'shipping_total' => ['sometimes', 'nullable', 'numeric'],
            'shipping_currency_mode' => ['sometimes', 'string', Rule::in(['auto', 'cad', 'vendor'])],
            'product_total' => ['sometimes', 'nullable', 'numeric'],
            'surcharge_total' => ['sometimes', 'nullable', 'numeric'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}



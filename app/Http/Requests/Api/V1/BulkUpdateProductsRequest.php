<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkUpdateProductsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],

            'changes' => ['required', 'array', 'min:1'],
            'changes.sku' => ['sometimes', 'required', 'string', 'max:64'],
            'changes.barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'changes.description' => ['sometimes', 'required', 'string', 'max:512'],
            'changes.handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.main_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'changes.type' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.department' => ['sometimes', 'nullable', 'string', 'max:64'],
            'changes.manufacturer' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.franchise' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.product_line' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.grade' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.scale' => ['sometimes', 'nullable', 'string', 'max:64'],
            'changes.series' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.vendor' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.published_on_shopify' => ['sometimes', 'required', 'boolean'],
            'changes.latest_arrival' => ['sometimes', 'required', 'boolean'],
            'changes.archived' => ['sometimes', 'required', 'boolean'],
            'changes.order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.filled' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.available' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.maintain' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.extended' => ['sometimes', 'nullable', 'numeric'],
            'changes.is_critical' => ['sometimes', 'required', 'boolean'],
            'changes.is_discontinued' => ['sometimes', 'required', 'boolean'],
            'changes.is_hazardous_shipment' => ['sometimes', 'required', 'boolean'],
            'changes.shipment_method' => ['sometimes', 'nullable', 'string', Rule::in(['air', 'sea'])],
        ];
    }
}

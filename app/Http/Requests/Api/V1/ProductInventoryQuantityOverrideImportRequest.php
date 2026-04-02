<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductInventoryQuantityOverrideImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
            'purchase_order_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'force' => ['sometimes', 'boolean'],
            // Default (omitted): set 0 available for products not present in the file.
            // Option: skip (leave products not present unchanged).
            'missing_products_mode' => ['sometimes', 'string', Rule::in(['set_zero', 'skip'])],
        ];
    }
}

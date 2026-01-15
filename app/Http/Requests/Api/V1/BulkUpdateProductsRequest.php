<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

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
            'changes.type' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.vendor' => ['sometimes', 'nullable', 'string', 'max:128'],
            'changes.published_on_shopify' => ['sometimes', 'required', 'boolean'],
            'changes.order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.filled' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.extended' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}




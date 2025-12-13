<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductsIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'sort_by' => [
                'sometimes',
                'string',
                Rule::in(['sku', 'barcode', 'description', 'type', 'price', 'order', 'filled', 'extended', 'updated_at', 'created_at']),
            ],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', 'max:40'],
        ];
    }
}



<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ProductsExportRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['shopify'])],
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

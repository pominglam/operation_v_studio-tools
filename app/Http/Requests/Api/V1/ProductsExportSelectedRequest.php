<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ProductsExportSelectedRequest extends FormRequest
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
            'export_type' => ['required', 'string', Rule::in(['shopify', 'missing_barcode', 'barcoded'])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', 'uuid'],
            'sort_by' => [
                'sometimes',
                'string',
                Rule::in(['sku', 'barcode', 'description', 'type', 'latest_landed_unit_cost', 'order', 'filled', 'extended', 'updated_at', 'created_at']),
            ],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}


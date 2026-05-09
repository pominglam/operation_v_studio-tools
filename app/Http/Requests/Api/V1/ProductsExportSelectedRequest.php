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
            'export_type' => ['required', 'string', Rule::in([
                'shopify',
                'shopify_no_inventory',
                'missing_barcode',
                'barcoded',
                'restock_po_cad',
                'restock_po_hkd',
            ])],
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['string', 'uuid'],
            // Shopify export normally skips products without selling price. Set this flag to include them anyway.
            'include_missing_selling_price' => ['sometimes', 'boolean'],
            'sort_by' => [
                'sometimes',
                'string',
                Rule::in([
                    'sku',
                    'barcode',
                    'description',
                    'main_type',
                    'type',
                    'grade',
                    'series',
                    'scale',
                    'vendor',
                    'latest_landed_unit_cost',
                    'received_date',
                    'selling_price',
                    'total_ordered',
                    'total_sold',
                    'order',
                    'filled',
                    'available',
                    'maintain',
                    'not_arrived',
                    'reorder',
                    'extended',
                    'po_total_cost',
                    'updated_at',
                    'created_at',
                ]),
            ],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}

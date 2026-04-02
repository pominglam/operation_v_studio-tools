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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'search_terms' => ['sometimes', 'array', 'max:60'],
            'search_terms.*' => ['string', 'max:200'],
            // Legacy single-select param (kept for backward compatibility)
            'purchase_order_uuid' => ['sometimes', 'nullable', 'uuid'],
            // New multi-select param
            'purchase_order_uuids' => ['sometimes', 'array', 'max:50'],
            'purchase_order_uuids.*' => ['uuid'],
            'po_product_novelty' => ['sometimes', 'string', Rule::in(['all', 'new', 'existing'])],
            'include_archived' => ['sometimes', 'boolean'],
            'ready' => ['sometimes', 'string', Rule::in(['all', 'ready', 'not_ready'])],
            'available' => ['sometimes', 'integer', 'min:0'],
            'not_arrived' => ['sometimes', 'integer', 'min:0'],
            'reorder' => ['sometimes', 'integer', 'min:0'],
            'reorder_gt_one' => ['sometimes', 'boolean'],
            'missing' => ['sometimes', 'array'],
            'missing.*' => [
                'string',
                Rule::in(['ok', 'pdp_description', 'pdp_images', 'barcode', 'selling_price', 'handle', 'not_ready', 'available_zero', 'maintain_empty']),
            ],
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
            'main_types' => ['sometimes', 'array'],
            'main_types.*' => ['string', 'max:64'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', 'max:40'],
            'vendors' => ['sometimes', 'array'],
            'vendors.*' => ['string', 'max:128'],
        ];
    }
}

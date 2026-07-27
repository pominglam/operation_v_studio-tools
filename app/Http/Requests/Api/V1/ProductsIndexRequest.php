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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
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
            'archived' => ['sometimes', 'string', Rule::in(['active', 'all', 'archived'])],
            'ready' => ['sometimes', 'string', Rule::in(['all', 'ready', 'not_ready'])],
            'published' => ['sometimes', 'string', Rule::in(['all', 'published', 'not_published'])],
            'product_flags' => ['sometimes', 'array'],
            'product_flags.*' => [
                'string',
                Rule::in(['critical', 'discontinued', 'hazardous_shipment']),
            ],
            'shipment_methods' => ['sometimes', 'array'],
            'shipment_methods.*' => ['string', Rule::in(['air', 'sea'])],
            'available_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'available_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'not_arrived' => ['sometimes', 'integer', 'min:0'],
            'not_arrived_include_draft_orders' => ['sometimes', 'boolean'],
            'reorder' => ['sometimes', 'integer', 'min:0'],
            'reorder_gt_one' => ['sometimes', 'boolean'],
            'selling_price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'selling_price_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
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
                    'received_date',
                    'selling_price',
                    'total_ordered',
                    'total_sold',
                    'order',
                    'filled',
                    'available',
                    'demand',
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

    public function archivedFilter(): string
    {
        $archived = $this->validated('archived');
        if (is_string($archived) && in_array($archived, ['all', 'archived'], true)) {
            return $archived;
        }

        if ((bool) ($this->validated('include_archived') ?? false)) {
            return 'all';
        }

        return 'active';
    }
}

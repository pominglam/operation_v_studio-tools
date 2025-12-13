<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PriceResearchProductsIndexRequest extends FormRequest
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
                Rule::in(['sku', 'description', 'price_researched_at', 'cost']),
            ],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],

            // Multi-select filters
            'freshness' => ['sometimes', 'array'],
            'freshness.*' => ['string', Rule::in(['fresh', 'expired'])],

            'quote_sites' => ['sometimes', 'array'],
            'quote_sites.*' => ['string', 'max:60'],

            'quote_statuses' => ['sometimes', 'array'],
            'quote_statuses.*' => ['string', Rule::in(['found', 'not_found', 'error'])],

            'quote_availabilities' => ['sometimes', 'array'],
            'quote_availabilities.*' => ['string', Rule::in(['in_stock', 'sold_out'])],
        ];
    }
}



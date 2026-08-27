<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductTaxonomyVerificationIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:250'],
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['proposed', 'verified', 'overridden']),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'maximum_confidence' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'archived' => ['sometimes', 'string', Rule::in(['active', 'all', 'archived'])],
            'differences_only' => ['sometimes', 'boolean'],
            'departments' => ['sometimes', 'array'],
            'departments.*' => ['string', 'max:64'],
            'manufacturers' => ['sometimes', 'array'],
            'manufacturers.*' => ['string', 'max:128'],
            'franchises' => ['sometimes', 'array'],
            'franchises.*' => ['string', 'max:128'],
            'product_lines' => ['sometimes', 'array'],
            'product_lines.*' => ['string', 'max:128'],
            'sublines' => ['sometimes', 'array'],
            'sublines.*' => ['string', 'max:128'],
            'grades' => ['sometimes', 'array'],
            'grades.*' => ['string', 'max:32'],
            'series_values' => ['sometimes', 'array'],
            'series_values.*' => ['string', 'max:255'],
            'scales' => ['sometimes', 'array'],
            'scales.*' => ['string', 'max:16'],
            'workshop_shelves' => ['sometimes', 'array'],
            'workshop_shelves.*' => ['string', 'max:128'],
            'accessory_kinds' => ['sometimes', 'array'],
            'accessory_kinds.*' => ['string', 'max:64'],
            'missing_fields' => ['sometimes', 'array'],
            'missing_fields.*' => [
                'string',
                Rule::in(self::canonicalFieldKeys()),
            ],
            'minimum_confidence' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewFilters(): array
    {
        $filters = $this->safe()->only([
            'status',
            'search',
            'maximum_confidence',
            'archived',
            'differences_only',
            'departments',
            'manufacturers',
            'franchises',
            'product_lines',
            'sublines',
            'grades',
            'series_values',
            'scales',
            'workshop_shelves',
            'accessory_kinds',
            'missing_fields',
            'minimum_confidence',
        ]);
        $filters['archived'] ??= 'all';
        $filters['differences_only'] = (bool) ($filters['differences_only'] ?? false);

        return $filters;
    }

    /**
     * @return list<string>
     */
    public static function canonicalFieldKeys(): array
    {
        return [
            'department',
            'manufacturer',
            'franchise',
            'product_line',
            'subline',
            'grade',
            'series',
            'scale',
            'workshop_shelf',
            'workshop_facets',
            'accessory_kind',
        ];
    }
}

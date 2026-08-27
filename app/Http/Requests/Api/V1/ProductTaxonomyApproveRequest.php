<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ProductTaxonomyApproveRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'values' => [
                'present',
                'array:department,manufacturer,franchise,product_line,subline,grade,series,scale,workshop_shelf,workshop_facets,accessory_kind',
            ],
            'values.department' => ['sometimes', 'nullable', 'string', 'max:64'],
            'values.manufacturer' => ['sometimes', 'nullable', 'string', 'max:128'],
            'values.franchise' => ['sometimes', 'nullable', 'string', 'max:128'],
            'values.product_line' => ['sometimes', 'nullable', 'string', 'max:128'],
            'values.subline' => ['sometimes', 'nullable', 'string', 'max:128'],
            'values.grade' => ['sometimes', 'nullable', 'string', 'max:128'],
            'values.series' => ['sometimes', 'nullable', 'string', 'max:255'],
            'values.scale' => ['sometimes', 'nullable', 'string', 'max:64'],
            'values.workshop_shelf' => ['sometimes', 'nullable', 'string', 'max:128'],
            'values.workshop_facets' => ['sometimes', 'nullable', 'array'],
            'values.accessory_kind' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operator' => ['required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

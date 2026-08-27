<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ProductTaxonomyBulkUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'operator' => ['required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verification_ids' => ['required', 'array', 'min:1'],
            'verification_ids.*' => ['uuid'],
            'values' => [
                'required',
                'array:department,manufacturer,franchise,product_line,subline,grade,series,scale,workshop_shelf,workshop_facets',
                'min:1',
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
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function values(): array
    {
        /** @var array<string, string|null> $values */
        $values = $this->validated('values');

        return $values;
    }
}

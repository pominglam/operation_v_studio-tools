<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlamodPreordersManufacturerFiltersUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'updates' => ['required', 'array', 'min:1'],
            'updates.*.id' => ['required', 'integer', 'exists:plamod_preorder_manufacturer_filters,id'],
            'updates.*.decision' => ['required', 'string', Rule::in(['undecided', 'include', 'exclude'])],
        ];
    }
}

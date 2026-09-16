<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\StorePreorders\StorePreorderIndexSort;
use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePreorderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'string', Rule::in(['all', ...StorePreorderStatus::ALL])],
            'sort_by' => ['sometimes', 'string', Rule::in(StorePreorderIndexSort::ALL)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'closes_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'has_units' => ['sometimes', 'boolean'],
        ];
    }
}

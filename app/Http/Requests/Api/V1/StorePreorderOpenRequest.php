<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StorePreorderOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.deposit_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'items.*.cap_qty' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'items.*.window_ends_on' => ['nullable', 'date'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0.01', 'max:99999.99'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StorePreorderManualOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'product_name' => ['required', 'string', 'max:512'],
            'description_html' => ['nullable', 'string', 'max:20000'],
            'selling_price' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'deposit_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'cap_qty' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'window_ends_on' => ['nullable', 'date'],
            'eta_date' => ['nullable', 'date'],
            'photo_ids' => ['nullable', 'array', 'max:12'],
            'photo_ids.*' => ['uuid'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodRestockSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'excluded_series' => ['sometimes', 'array', 'max:100'],
            'excluded_series.*' => ['required', 'string', 'min:2', 'max:191'],
            'excluded_product_terms' => ['sometimes', 'array', 'max:100'],
            'excluded_product_terms.*' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}

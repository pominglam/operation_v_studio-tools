<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:512'],
            'handle' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:128'],
            'vendor' => ['nullable', 'string', 'max:128'],
            'order' => ['nullable', 'integer', 'min:0'],
            'filled' => ['nullable', 'integer', 'min:0'],
            'available' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'extended' => ['nullable', 'numeric'],
        ];
    }
}

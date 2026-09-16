<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ShopifyCustomerSuggestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class LockCustomAsiaOrderOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'our_price_multiplier' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'customer_price_cad' => ['required', 'numeric', 'gt:0'],
            'deposit_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}

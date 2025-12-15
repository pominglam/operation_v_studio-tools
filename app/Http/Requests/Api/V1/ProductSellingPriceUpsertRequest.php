<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductSellingPriceUpsertRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'currency' => ['sometimes', 'string', Rule::in(['CAD'])],
        ];
    }
}

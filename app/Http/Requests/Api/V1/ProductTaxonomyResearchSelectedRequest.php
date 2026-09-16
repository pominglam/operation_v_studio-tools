<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductTaxonomyResearchSelectedRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'verification_ids' => ['required', 'array', 'min:1', 'max:200'],
            'verification_ids.*' => ['uuid'],
            'fields' => ['sometimes', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(ProductTaxonomyFields::ALL)],
        ];
    }
}

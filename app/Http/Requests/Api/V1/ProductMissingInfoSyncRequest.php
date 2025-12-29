<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductMissingInfoSyncRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:200'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', 'max:40'],
            'vendors' => ['sometimes', 'array'],
            'vendors.*' => ['string', 'max:128'],
            'missing' => ['sometimes', 'array'],
            'missing.*' => [
                'string',
                Rule::in(['pdp_description', 'pdp_images', 'barcode', 'selling_price']),
            ],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}



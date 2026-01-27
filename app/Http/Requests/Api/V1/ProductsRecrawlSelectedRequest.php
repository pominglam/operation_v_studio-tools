<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductsRecrawlSelectedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['string', 'uuid'],
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => [
                'string',
                Rule::in(['bandai', 'hlj', 'plamod', 'competitor_price_research']),
            ],
        ];
    }
}


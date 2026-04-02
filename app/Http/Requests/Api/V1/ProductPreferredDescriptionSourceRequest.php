<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductPreferredDescriptionSourceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferred_description_source' => [
                'nullable',
                'string',
                Rule::in(['bandai', 'hlj', 'plamod', 'gundamplanet', 'newtype', 'gundamhangar', 'other']),
            ],
            'manual_description_html' => [
                'sometimes',
                'nullable',
                'string',
                'max:65535',
            ],
        ];
    }
}


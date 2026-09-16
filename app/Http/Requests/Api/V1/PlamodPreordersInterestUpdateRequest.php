<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodPreordersInterestUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'skus' => ['required', 'array', 'min:1', 'max:200'],
            'skus.*' => ['required', 'string', 'max:64'],
            'not_interested' => ['required', 'boolean'],
        ];
    }
}

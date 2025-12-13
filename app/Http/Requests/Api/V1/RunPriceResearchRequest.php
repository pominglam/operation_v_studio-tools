<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class RunPriceResearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}



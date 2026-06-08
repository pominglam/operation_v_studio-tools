<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodPreordersSearchLinesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['string', 'max:500'],
            'phase' => ['sometimes', 'string', 'in:snapshot,live,all'],
        ];
    }
}

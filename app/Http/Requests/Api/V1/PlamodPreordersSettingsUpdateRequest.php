<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodPreordersSettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'excluded_categories' => ['present', 'array'],
            'excluded_categories.*' => ['string', 'max:128'],
        ];
    }
}

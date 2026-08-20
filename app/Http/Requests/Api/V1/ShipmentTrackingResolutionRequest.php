<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ShipmentTrackingResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'tracking_numbers' => ['required', 'array', 'min:1', 'max:200'],
            'tracking_numbers.*' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[\pL\pN._\-\/ ]+$/u'],
        ];
    }
}

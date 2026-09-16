<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StorePreorderCapUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('cap_qty');
        if ($raw === '' || $raw === '0' || $raw === 0) {
            $this->merge(['cap_qty' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cap_qty' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ];
    }
}

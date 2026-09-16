<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\StorePreorders\OpvCatalogPricingSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class OpvCatalogPricingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'price_multiplier' => ['required_without:reset', 'nullable', 'numeric', 'min:1', 'max:5'],
            'default_deposit_percent' => ['required_without:reset', 'nullable', 'numeric', 'min:1', 'max:100'],
            'reset' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('reset')) {
                return;
            }

            try {
                OpvCatalogPricingSettings::normalize([
                    'price_multiplier' => $this->input('price_multiplier'),
                    'default_deposit_percent' => $this->input('default_deposit_percent'),
                ]);
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('price_multiplier', $exception->getMessage());
            }
        });
    }
}

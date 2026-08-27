<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CustomAsiaOrderPricingCapsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchandiser_commission_cap_cad' => ['required_without:reset', 'nullable', 'numeric', 'min:0'],
            'opv_margin_cap_cad' => ['required_without:reset', 'nullable', 'numeric', 'min:0'],
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
                \App\Support\CustomOrders\CustomAsiaOrderPricingCaps::normalize([
                    'merchandiser_commission_cap_cad' => $this->input('merchandiser_commission_cap_cad'),
                    'opv_margin_cap_cad' => $this->input('opv_margin_cap_cad'),
                ]);
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('merchandiser_commission_cap_cad', $exception->getMessage());
            }
        });
    }
}

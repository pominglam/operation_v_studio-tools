<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SpecialOrderPricingCapsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchandiser_commission_cap_cad' => ['required_without:reset', 'nullable', 'numeric', 'min:0'],
            'opv_margin_cap_cad' => ['required_without:reset', 'nullable', 'numeric', 'min:0'],
            'default_shipping_cost_amount' => ['required_without:reset', 'nullable', 'numeric', 'min:0'],
            'default_shipping_cost_currency' => ['required_without:reset', 'nullable', 'string', 'in:CAD,CNY,HKD,JPY,RMB'],
            'default_shipping_cost_per_kg_cny' => ['required_without:reset', 'nullable', 'numeric', 'min:0'],
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
                \App\Support\SpecialOrders\SpecialOrderPricingCaps::normalize([
                    'merchandiser_commission_cap_cad' => $this->input('merchandiser_commission_cap_cad'),
                    'opv_margin_cap_cad' => $this->input('opv_margin_cap_cad'),
                    'default_shipping_cost_amount' => $this->input('default_shipping_cost_amount'),
                    'default_shipping_cost_currency' => $this->input('default_shipping_cost_currency'),
                    'default_shipping_cost_per_kg_cny' => $this->input('default_shipping_cost_per_kg_cny'),
                ]);
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('merchandiser_commission_cap_cad', $exception->getMessage());
            }
        });
    }
}

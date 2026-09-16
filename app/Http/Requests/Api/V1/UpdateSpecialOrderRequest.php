<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\SpecialOrders\SpecialOrderContactMedia;
use App\Support\SpecialOrders\SpecialOrderCurrency;
use App\Support\SpecialOrders\SpecialOrderReceiveDelayUnit;
use App\Support\SpecialOrders\SpecialOrderShippingCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSpecialOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_contact_media' => ['sometimes', 'string', Rule::in(SpecialOrderContactMedia::ALL)],
            'customer_contact_value' => ['sometimes', 'string', 'max:255'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'product_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'product_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderCurrency::ALL)],
            'shipping_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'shipping_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderCurrency::ALL)],
            'shipping_cost_input_mode' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderShippingCost::MODES)],
            'shipping_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'receive_delay_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'receive_delay_unit' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderReceiveDelayUnit::ALL)],
            'actual_product_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_product_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderCurrency::ALL)],
            'actual_shipping_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_shipping_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderCurrency::ALL)],
            'actual_shipping_cost_input_mode' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderShippingCost::MODES)],
            'actual_shipping_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_receive_delay_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'actual_receive_delay_unit' => ['sometimes', 'nullable', 'string', Rule::in(SpecialOrderReceiveDelayUnit::ALL)],
            'actual_arrival_at' => ['sometimes', 'nullable', 'date'],
            'merchandiser_price_multiplier' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:99.99'],
            'merchandiser_price_cad' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'merchandiser_commission_override_cad' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'our_price_multiplier' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:99.99'],
            'customer_price_cad' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'our_commission_override_cad' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'deposit_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'deposit_amount_override_cad' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}

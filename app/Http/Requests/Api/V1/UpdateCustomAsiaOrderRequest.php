<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use App\Support\CustomOrders\CustomAsiaOrderCurrency;
use App\Support\CustomOrders\CustomAsiaOrderReceiveDelayUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomAsiaOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_contact_media' => ['sometimes', 'string', Rule::in(CustomAsiaOrderContactMedia::ALL)],
            'customer_contact_value' => ['sometimes', 'string', 'max:255'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'product_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'product_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(CustomAsiaOrderCurrency::ALL)],
            'shipping_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'shipping_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(CustomAsiaOrderCurrency::ALL)],
            'receive_delay_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'receive_delay_unit' => ['sometimes', 'nullable', 'string', Rule::in(CustomAsiaOrderReceiveDelayUnit::ALL)],
            'actual_product_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_product_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(CustomAsiaOrderCurrency::ALL)],
            'actual_shipping_cost_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_shipping_cost_currency' => ['sometimes', 'nullable', 'string', Rule::in(CustomAsiaOrderCurrency::ALL)],
            'actual_receive_delay_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'actual_receive_delay_unit' => ['sometimes', 'nullable', 'string', Rule::in(CustomAsiaOrderReceiveDelayUnit::ALL)],
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

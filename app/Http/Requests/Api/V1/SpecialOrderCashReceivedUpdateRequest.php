<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Support\SpecialOrders\SpecialOrderCustomerPricing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SpecialOrderCashReceivedUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('cash_received_cad');
        if (! is_string($raw)) {
            return;
        }

        $normalized = str_replace(['$', ',', ' '], '', trim($raw));

        $this->merge([
            'cash_received_cad' => $normalized === '' ? null : $normalized,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cash_received_cad' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $order = app(SpecialOrderRepository::class)->findByUuidOrFail((string) $this->route('id'));
            $customerPrice = SpecialOrderCustomerPricing::normalizeMoney($order->customer_price_cad);
            $cash = SpecialOrderCustomerPricing::normalizeMoney($this->input('cash_received_cad'));

            if ($customerPrice === null || $cash === null) {
                return;
            }

            if ((float) $cash > (float) $customerPrice) {
                $validator->errors()->add(
                    'cash_received_cad',
                    'Cash received cannot exceed the customer price ('.$customerPrice.' CAD).',
                );
            }
        });
    }
}

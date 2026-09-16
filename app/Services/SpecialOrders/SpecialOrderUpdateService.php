<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use App\Support\SpecialOrders\SpecialOrderContactMedia;
use App\Support\SpecialOrders\SpecialOrderCurrency;
use App\Support\SpecialOrders\SpecialOrderReceiveDelayUnit;
use App\Support\SpecialOrders\SpecialOrderShippingCost;

final class SpecialOrderUpdateService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
        private readonly SpecialOrderLandedCostService $landedCost,
        private readonly SpecialOrderCustomerPricingService $customerPricing,
        private readonly SpecialOrderMilestoneService $milestones,
        private readonly SpecialOrderPricingCapsService $pricingCaps,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $uuid, array $input): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $attributes = [];

        if (array_key_exists('customer_contact_media', $input)) {
            $media = SpecialOrderContactMedia::normalize((string) $input['customer_contact_media']);
            if ($media === null) {
                throw new \InvalidArgumentException('Invalid customer contact media.');
            }
            $attributes['customer_contact_media'] = $media;
        }

        if (array_key_exists('customer_contact_value', $input)) {
            $value = trim((string) $input['customer_contact_value']);
            if ($value === '') {
                throw new \InvalidArgumentException('Customer contact is required.');
            }
            $attributes['customer_contact_value'] = $value;
        }

        if (array_key_exists('product_name', $input)) {
            $productName = trim((string) $input['product_name']);
            if ($productName === '') {
                throw new \InvalidArgumentException('Product name is required.');
            }
            $attributes['product_name'] = $productName;
        }

        if (array_key_exists('vendor', $input)) {
            $vendor = $input['vendor'];
            $attributes['vendor'] = is_string($vendor) && trim($vendor) !== '' ? trim($vendor) : null;
        }

        if (array_key_exists('notes', $input)) {
            $notes = $input['notes'];
            $attributes['notes'] = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        }

        $productAmount = $order->product_cost_amount;
        $productCurrency = $order->product_cost_currency;
        $shippingAmount = $order->shipping_cost_amount;
        $shippingCurrency = $order->shipping_cost_currency;

        if (array_key_exists('product_cost_amount', $input)) {
            $raw = $input['product_cost_amount'];
            $productAmount = $raw === null || $raw === '' ? null : number_format((float) $raw, 2, '.', '');
            $attributes['product_cost_amount'] = $productAmount;
        }

        if (array_key_exists('product_cost_currency', $input)) {
            $currency = $input['product_cost_currency'];
            if ($currency === null || $currency === '') {
                $productCurrency = null;
                $attributes['product_cost_currency'] = null;
            } else {
                $normalized = SpecialOrderCurrency::normalize((string) $currency);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('Invalid product cost currency.');
                }
                $productCurrency = $normalized;
                $attributes['product_cost_currency'] = $normalized;
            }
        }

        if ($this->quoteShippingInputTouched($input)) {
            $resolved = SpecialOrderShippingCost::resolveQuote(
                array_key_exists('shipping_cost_input_mode', $input)
                    ? (is_string($input['shipping_cost_input_mode']) ? $input['shipping_cost_input_mode'] : null)
                    : $order->shipping_cost_input_mode,
                array_key_exists('shipping_weight_kg', $input) ? $input['shipping_weight_kg'] : $order->shipping_weight_kg,
                array_key_exists('shipping_cost_amount', $input) ? $input['shipping_cost_amount'] : $order->shipping_cost_amount,
                array_key_exists('shipping_cost_currency', $input)
                    ? (is_string($input['shipping_cost_currency']) ? $input['shipping_cost_currency'] : null)
                    : $order->shipping_cost_currency,
                $this->pricingCaps->getCaps()['default_shipping_cost_per_kg_cny'],
            );
            $attributes = array_merge($attributes, $resolved);
            $shippingAmount = $resolved['shipping_cost_amount'];
            $shippingCurrency = $resolved['shipping_cost_currency'];
        }

        if (array_key_exists('receive_delay_amount', $input) || array_key_exists('receive_delay_unit', $input)) {
            $delayAmount = array_key_exists('receive_delay_amount', $input)
                ? $input['receive_delay_amount']
                : $order->receive_delay_amount;
            $delayUnit = array_key_exists('receive_delay_unit', $input)
                ? $input['receive_delay_unit']
                : $order->receive_delay_unit;

            if ($delayAmount === null || $delayAmount === '') {
                $attributes['receive_delay_amount'] = null;
                $attributes['receive_delay_unit'] = null;
                $attributes['receive_delay_days'] = null;
            } else {
                $amount = (int) $delayAmount;
                if ($amount < 1) {
                    throw new \InvalidArgumentException('Shipping delay must be at least 1.');
                }
                $unit = SpecialOrderReceiveDelayUnit::normalize(is_string($delayUnit) ? $delayUnit : null);
                if ($unit === null) {
                    throw new \InvalidArgumentException('Shipping delay unit is required.');
                }
                $attributes['receive_delay_amount'] = $amount;
                $attributes['receive_delay_unit'] = $unit;
                $attributes['receive_delay_days'] = SpecialOrderReceiveDelayUnit::toDays($amount, $unit);
            }
        }

        $actualProductAmount = $order->actual_product_cost_amount;
        $actualProductCurrency = $order->actual_product_cost_currency;
        $actualShippingAmount = $order->actual_shipping_cost_amount;
        $actualShippingCurrency = $order->actual_shipping_cost_currency;

        if (array_key_exists('actual_product_cost_amount', $input)) {
            $raw = $input['actual_product_cost_amount'];
            $actualProductAmount = $raw === null || $raw === '' ? null : number_format((float) $raw, 2, '.', '');
            $attributes['actual_product_cost_amount'] = $actualProductAmount;
        }

        if (array_key_exists('actual_product_cost_currency', $input)) {
            $currency = $input['actual_product_cost_currency'];
            if ($currency === null || $currency === '') {
                $actualProductCurrency = null;
                $attributes['actual_product_cost_currency'] = null;
            } else {
                $normalized = SpecialOrderCurrency::normalize((string) $currency);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('Invalid actual product cost currency.');
                }
                $actualProductCurrency = $normalized;
                $attributes['actual_product_cost_currency'] = $normalized;
            }
        }

        if ($this->actualShippingInputTouched($input)) {
            $resolved = SpecialOrderShippingCost::resolveActual(
                array_key_exists('actual_shipping_cost_input_mode', $input)
                    ? (is_string($input['actual_shipping_cost_input_mode']) ? $input['actual_shipping_cost_input_mode'] : null)
                    : $order->actual_shipping_cost_input_mode,
                array_key_exists('actual_shipping_weight_kg', $input) ? $input['actual_shipping_weight_kg'] : $order->actual_shipping_weight_kg,
                array_key_exists('actual_shipping_cost_amount', $input) ? $input['actual_shipping_cost_amount'] : $order->actual_shipping_cost_amount,
                array_key_exists('actual_shipping_cost_currency', $input)
                    ? (is_string($input['actual_shipping_cost_currency']) ? $input['actual_shipping_cost_currency'] : null)
                    : $order->actual_shipping_cost_currency,
                $this->pricingCaps->getCaps()['default_shipping_cost_per_kg_cny'],
            );
            $attributes = array_merge($attributes, $resolved);
            $actualShippingAmount = $resolved['actual_shipping_cost_amount'];
            $actualShippingCurrency = $resolved['actual_shipping_cost_currency'];
        }

        if (array_key_exists('actual_receive_delay_amount', $input) || array_key_exists('actual_receive_delay_unit', $input)) {
            $delayAmount = array_key_exists('actual_receive_delay_amount', $input)
                ? $input['actual_receive_delay_amount']
                : $order->actual_receive_delay_amount;
            $delayUnit = array_key_exists('actual_receive_delay_unit', $input)
                ? $input['actual_receive_delay_unit']
                : $order->actual_receive_delay_unit;

            if ($delayAmount === null || $delayAmount === '') {
                $attributes['actual_receive_delay_amount'] = null;
                $attributes['actual_receive_delay_unit'] = null;
                $attributes['actual_receive_delay_days'] = null;
            } else {
                $amount = (int) $delayAmount;
                if ($amount < 1) {
                    throw new \InvalidArgumentException('Actual shipping delay must be at least 1.');
                }
                $unit = SpecialOrderReceiveDelayUnit::normalize(is_string($delayUnit) ? $delayUnit : null);
                if ($unit === null) {
                    throw new \InvalidArgumentException('Actual shipping delay unit is required.');
                }
                $attributes['actual_receive_delay_amount'] = $amount;
                $attributes['actual_receive_delay_unit'] = $unit;
                $attributes['actual_receive_delay_days'] = SpecialOrderReceiveDelayUnit::toDays($amount, $unit);
            }
        }

        if (array_key_exists('actual_arrival_at', $input)) {
            $raw = $input['actual_arrival_at'];
            $attributes['actual_arrival_at'] = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
        }

        $costs = $this->landedCost->compute(
            is_string($productAmount) ? $productAmount : null,
            is_string($productCurrency) ? $productCurrency : null,
            is_string($shippingAmount) ? $shippingAmount : null,
            is_string($shippingCurrency) ? $shippingCurrency : null,
        );

        $attributes = array_merge($attributes, $costs);

        $actualCosts = $this->landedCost->compute(
            is_string($actualProductAmount) ? $actualProductAmount : null,
            is_string($actualProductCurrency) ? $actualProductCurrency : null,
            is_string($actualShippingAmount) ? $actualShippingAmount : null,
            is_string($actualShippingCurrency) ? $actualShippingCurrency : null,
        );

        $attributes['actual_landed_cost_cad'] = $actualCosts['landed_cost_cad'];
        $attributes['actual_product_fx_rate_to_cad'] = $actualCosts['product_fx_rate_to_cad'];
        $attributes['actual_shipping_fx_rate_to_cad'] = $actualCosts['shipping_fx_rate_to_cad'];
        $attributes['actual_fx_rate_date'] = $actualCosts['fx_rate_date'];

        if ($order->isOfferLocked() && $this->customerPricing->hasCustomerOfferInput($input)) {
            throw new \InvalidArgumentException('Customer offer is locked and cannot be changed.');
        }

        $attributes = array_merge($attributes, $this->customerPricing->resolveUpdateAttributes($order, $input));

        $order = $this->orders->update($order, $attributes);

        if ($order->merchandiser_ordered_at !== null) {
            $this->milestones->syncEstimatedArrival($order);
            $order->refresh();
        }

        return $order;
    }

    /**
     * Copy merchandiser quote costs into reconciliation actuals when actuals are still unset.
     *
     * @return array<string, mixed>
     */
    public function seedReconciliationFromQuoteAttributes(SpecialOrder $order): array
    {
        $attributes = [];

        $actualProductAmount = $order->actual_product_cost_amount;
        $actualProductCurrency = $order->actual_product_cost_currency;
        $actualShippingAmount = $order->actual_shipping_cost_amount;
        $actualShippingCurrency = $order->actual_shipping_cost_currency;

        if ($actualProductAmount === null && $order->product_cost_amount !== null) {
            $attributes['actual_product_cost_amount'] = $order->product_cost_amount;
            $attributes['actual_product_cost_currency'] = $order->product_cost_currency ?? SpecialOrderCurrency::CNY;
            $actualProductAmount = $attributes['actual_product_cost_amount'];
            $actualProductCurrency = $attributes['actual_product_cost_currency'];
        }

        if ($actualShippingAmount === null && $order->shipping_cost_amount !== null) {
            $attributes['actual_shipping_cost_amount'] = $order->shipping_cost_amount;
            $attributes['actual_shipping_cost_currency'] = $order->shipping_cost_currency ?? SpecialOrderCurrency::CNY;
            $attributes['actual_shipping_cost_input_mode'] = SpecialOrderShippingCost::normalizeMode(
                $order->shipping_cost_input_mode,
            );
            $attributes['actual_shipping_weight_kg'] = $order->shipping_weight_kg;
            $actualShippingAmount = $attributes['actual_shipping_cost_amount'];
            $actualShippingCurrency = $attributes['actual_shipping_cost_currency'];
        }

        if ($attributes === []) {
            return [];
        }

        $actualCosts = $this->landedCost->compute(
            is_string($actualProductAmount) ? $actualProductAmount : null,
            is_string($actualProductCurrency) ? $actualProductCurrency : null,
            is_string($actualShippingAmount) ? $actualShippingAmount : null,
            is_string($actualShippingCurrency) ? $actualShippingCurrency : null,
        );

        return array_merge($attributes, [
            'actual_landed_cost_cad' => $actualCosts['landed_cost_cad'],
            'actual_product_fx_rate_to_cad' => $actualCosts['product_fx_rate_to_cad'],
            'actual_shipping_fx_rate_to_cad' => $actualCosts['shipping_fx_rate_to_cad'],
            'actual_fx_rate_date' => $actualCosts['fx_rate_date'],
        ]);
    }

    /** @param  array<string, mixed>  $input */
    private function quoteShippingInputTouched(array $input): bool
    {
        return array_key_exists('shipping_cost_input_mode', $input)
            || array_key_exists('shipping_weight_kg', $input)
            || array_key_exists('shipping_cost_amount', $input)
            || array_key_exists('shipping_cost_currency', $input);
    }

    /** @param  array<string, mixed>  $input */
    private function actualShippingInputTouched(array $input): bool
    {
        return array_key_exists('actual_shipping_cost_input_mode', $input)
            || array_key_exists('actual_shipping_weight_kg', $input)
            || array_key_exists('actual_shipping_cost_amount', $input)
            || array_key_exists('actual_shipping_cost_currency', $input);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use App\Support\CustomOrders\CustomAsiaOrderCurrency;
use App\Support\CustomOrders\CustomAsiaOrderReceiveDelayUnit;

final class CustomAsiaOrderUpdateService
{
    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
        private readonly CustomAsiaOrderLandedCostService $landedCost,
        private readonly CustomAsiaOrderCustomerPricingService $customerPricing,
        private readonly CustomAsiaOrderMilestoneService $milestones,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $uuid, array $input): CustomAsiaOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $attributes = [];

        if (array_key_exists('customer_contact_media', $input)) {
            $media = CustomAsiaOrderContactMedia::normalize((string) $input['customer_contact_media']);
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
                $normalized = CustomAsiaOrderCurrency::normalize((string) $currency);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('Invalid product cost currency.');
                }
                $productCurrency = $normalized;
                $attributes['product_cost_currency'] = $normalized;
            }
        }

        if (array_key_exists('shipping_cost_amount', $input)) {
            $raw = $input['shipping_cost_amount'];
            $shippingAmount = $raw === null || $raw === '' ? null : number_format((float) $raw, 2, '.', '');
            $attributes['shipping_cost_amount'] = $shippingAmount;
        }

        if (array_key_exists('shipping_cost_currency', $input)) {
            $currency = $input['shipping_cost_currency'];
            if ($currency === null || $currency === '') {
                $shippingCurrency = null;
                $attributes['shipping_cost_currency'] = null;
            } else {
                $normalized = CustomAsiaOrderCurrency::normalize((string) $currency);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('Invalid shipping cost currency.');
                }
                $shippingCurrency = $normalized;
                $attributes['shipping_cost_currency'] = $normalized;
            }
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
                $unit = CustomAsiaOrderReceiveDelayUnit::normalize(is_string($delayUnit) ? $delayUnit : null);
                if ($unit === null) {
                    throw new \InvalidArgumentException('Shipping delay unit is required.');
                }
                $attributes['receive_delay_amount'] = $amount;
                $attributes['receive_delay_unit'] = $unit;
                $attributes['receive_delay_days'] = CustomAsiaOrderReceiveDelayUnit::toDays($amount, $unit);
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
                $normalized = CustomAsiaOrderCurrency::normalize((string) $currency);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('Invalid actual product cost currency.');
                }
                $actualProductCurrency = $normalized;
                $attributes['actual_product_cost_currency'] = $normalized;
            }
        }

        if (array_key_exists('actual_shipping_cost_amount', $input)) {
            $raw = $input['actual_shipping_cost_amount'];
            $actualShippingAmount = $raw === null || $raw === '' ? null : number_format((float) $raw, 2, '.', '');
            $attributes['actual_shipping_cost_amount'] = $actualShippingAmount;
        }

        if (array_key_exists('actual_shipping_cost_currency', $input)) {
            $currency = $input['actual_shipping_cost_currency'];
            if ($currency === null || $currency === '') {
                $actualShippingCurrency = null;
                $attributes['actual_shipping_cost_currency'] = null;
            } else {
                $normalized = CustomAsiaOrderCurrency::normalize((string) $currency);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('Invalid actual shipping cost currency.');
                }
                $actualShippingCurrency = $normalized;
                $attributes['actual_shipping_cost_currency'] = $normalized;
            }
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
                $unit = CustomAsiaOrderReceiveDelayUnit::normalize(is_string($delayUnit) ? $delayUnit : null);
                if ($unit === null) {
                    throw new \InvalidArgumentException('Actual shipping delay unit is required.');
                }
                $attributes['actual_receive_delay_amount'] = $amount;
                $attributes['actual_receive_delay_unit'] = $unit;
                $attributes['actual_receive_delay_days'] = CustomAsiaOrderReceiveDelayUnit::toDays($amount, $unit);
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
}

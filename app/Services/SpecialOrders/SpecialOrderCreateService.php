<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use App\Support\SpecialOrders\SpecialOrderContactMedia;
use App\Support\SpecialOrders\SpecialOrderReceiveDelayUnit;
use App\Support\SpecialOrders\SpecialOrderShippingCost;

final class SpecialOrderCreateService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
        private readonly SpecialOrderPricingCapsService $pricingCaps,
        private readonly SpecialOrderCompetitorPricesRefreshService $competitorPrices,
    ) {}

    /**
     * @param  array{
     *   customer_contact_media: string,
     *   customer_contact_value: string,
     *   product_name: string,
     *   vendor?: string|null,
     *   notes?: string|null
     * }  $input
     */
    public function create(array $input): SpecialOrder
    {
        $media = SpecialOrderContactMedia::normalize($input['customer_contact_media']);
        if ($media === null) {
            throw new \InvalidArgumentException('Invalid customer contact media.');
        }

        $value = trim($input['customer_contact_value']);
        if ($value === '') {
            throw new \InvalidArgumentException('Customer contact is required.');
        }

        $productName = trim($input['product_name']);
        if ($productName === '') {
            throw new \InvalidArgumentException('Product name is required.');
        }

        $quoteDefaults = $this->pricingCaps->getCaps();

        $order = $this->orders->create([
            'customer_contact_media' => $media,
            'customer_contact_value' => $value,
            'product_name' => $productName,
            'vendor' => isset($input['vendor']) && is_string($input['vendor']) && trim($input['vendor']) !== ''
                ? trim($input['vendor'])
                : null,
            'notes' => isset($input['notes']) ? trim((string) $input['notes']) : null,
            'receive_delay_amount' => SpecialOrderReceiveDelayUnit::DEFAULT_AMOUNT,
            'receive_delay_unit' => SpecialOrderReceiveDelayUnit::DEFAULT_UNIT,
            'receive_delay_days' => SpecialOrderReceiveDelayUnit::defaultDays(),
            'shipping_cost_amount' => $quoteDefaults['default_shipping_cost_amount'],
            'shipping_cost_currency' => $quoteDefaults['default_shipping_cost_currency'],
            'shipping_cost_input_mode' => SpecialOrderShippingCost::MODE_AMOUNT,
        ]);

        if (strlen($productName) >= 3) {
            return $this->competitorPrices->queueRefresh($order->uuid, 'full');
        }

        return $order;
    }
}

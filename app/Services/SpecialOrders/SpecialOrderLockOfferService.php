<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use Illuminate\Support\Carbon;

final class SpecialOrderLockOfferService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
        private readonly SpecialOrderCustomerPricingService $customerPricing,
        private readonly SpecialOrderUpdateService $updates,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function lock(string $uuid, array $input): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if (! $order->isQuoted()) {
            throw new \InvalidArgumentException('Complete the merchandiser quote before locking the customer offer.');
        }

        if ($order->customer_offer_locked_at !== null) {
            return $order;
        }

        $pricingAttributes = $this->customerPricing->resolveCustomerOfferAttributes($order, $input);

        $customerPrice = $pricingAttributes['customer_price_cad'] ?? $order->customer_price_cad;
        $depositPercent = $pricingAttributes['deposit_percent'] ?? $order->deposit_percent;
        $depositAmountOverride = $pricingAttributes['deposit_amount_override_cad'] ?? $order->deposit_amount_override_cad;

        if ($customerPrice === null || ($depositPercent === null && $depositAmountOverride === null)) {
            throw new \InvalidArgumentException('Customer price and deposit are required to lock the offer.');
        }

        $attributes = array_merge(
            $pricingAttributes,
            $this->updates->seedReconciliationFromQuoteAttributes($order),
            [
                'customer_offer_locked_at' => Carbon::now('America/Toronto'),
            ],
        );

        return $this->orders->update($order, $attributes);
    }

    public function unlock(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->customer_offer_locked_at === null) {
            return $order;
        }

        if ($order->isRejected()) {
            throw new \InvalidArgumentException('Rejected orders cannot unlock the customer offer.');
        }

        if ($order->deposit_received_at !== null) {
            throw new \InvalidArgumentException('Cannot unlock the offer after deposit is received.');
        }

        if ($order->merchandiser_ordered_at !== null) {
            throw new \InvalidArgumentException('Cannot unlock the offer after the merchandiser order is placed.');
        }

        if ($order->product_received_at !== null) {
            throw new \InvalidArgumentException('Cannot unlock the offer after the product is received.');
        }

        return $this->orders->update($order, [
            'customer_offer_locked_at' => null,
        ]);
    }
}

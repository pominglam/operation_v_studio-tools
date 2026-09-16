<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use App\Support\SpecialOrders\SpecialOrderCustomerPricing;
use Illuminate\Support\Carbon;

final class SpecialOrderCashPaymentService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
    ) {}

    public function recordCashReceived(string $uuid, string $cashReceivedCad): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if (! $order->isOfferLocked()) {
            throw new \InvalidArgumentException('Lock the customer offer before recording cash payment.');
        }

        $customerPrice = SpecialOrderCustomerPricing::normalizeMoney($order->customer_price_cad);
        if ($customerPrice === null) {
            throw new \InvalidArgumentException('Set customer price before recording cash payment.');
        }

        $normalized = SpecialOrderCustomerPricing::normalizeMoney($cashReceivedCad);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Cash received must be a valid amount.');
        }

        $cash = (float) $normalized;
        $customerPrice = (float) ($order->customer_price_cad ?? '0');
        if ($customerPrice <= 0) {
            throw new \InvalidArgumentException('Customer price is required before recording cash payment.');
        }

        if ($cash > $customerPrice) {
            throw new \InvalidArgumentException('Cash received cannot exceed the customer price.');
        }

        if ($order->balance_received_at !== null) {
            $existingCash = SpecialOrderCustomerPricing::normalizeMoney($order->cash_received_cad);
            if ($existingCash !== null && (float) $existingCash === $cash) {
                return $order;
            }

            throw new \InvalidArgumentException('This order is paid in full. Cash received cannot be changed.');
        }

        $depositDue = (float) (
            SpecialOrderCustomerPricing::depositAmount(
                is_string($order->customer_price_cad) ? $order->customer_price_cad : null,
                is_string($order->deposit_percent) ? $order->deposit_percent : null,
                is_string($order->deposit_amount_override_cad) ? $order->deposit_amount_override_cad : null,
            ) ?? '0'
        );

        $now = Carbon::now('America/Toronto');
        $updates = [
            'cash_received_cad' => $normalized,
            'cash_received_at' => $cash > 0 ? $now : null,
            'customer_considering_at' => null,
        ];

        if ($cash <= 0) {
            $updates['deposit_received_at'] = null;
            $updates['balance_received_at'] = null;
        } else {
            if ($depositDue > 0) {
                $updates['deposit_received_at'] = $cash >= $depositDue
                    ? ($order->deposit_received_at ?? $now)
                    : null;
            }

            if ($cash >= $customerPrice) {
                $updates['balance_received_at'] = $order->balance_received_at ?? $now;
                if ($depositDue <= 0) {
                    $updates['deposit_received_at'] = $order->deposit_received_at ?? $now;
                }
            } else {
                $updates['balance_received_at'] = null;
            }
        }

        return $this->orders->update($order, $updates);
    }
}

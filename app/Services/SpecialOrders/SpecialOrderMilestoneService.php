<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use App\Support\SpecialOrders\SpecialOrderEta;
use Illuminate\Support\Carbon;

final class SpecialOrderMilestoneService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
    ) {}

    public function markDepositReceived(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if (! $order->isOfferLocked()) {
            throw new \InvalidArgumentException('Lock the customer offer before marking deposit received.');
        }

        if ($order->deposit_received_at !== null) {
            return $order;
        }

        return $this->orders->update($order, [
            'deposit_received_at' => Carbon::now('America/Toronto'),
            'customer_considering_at' => null,
        ]);
    }

    public function markBalanceReceived(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if (! $order->isOfferLocked()) {
            throw new \InvalidArgumentException('Lock the customer offer before marking balance received.');
        }

        if ($order->deposit_received_at === null) {
            throw new \InvalidArgumentException('Mark deposit received before marking balance received.');
        }

        if ($order->product_received_at === null) {
            throw new \InvalidArgumentException('Mark product in hand before marking balance received.');
        }

        if ($order->balance_received_at !== null) {
            return $order;
        }

        return $this->orders->update($order, [
            'balance_received_at' => Carbon::now('America/Toronto'),
        ]);
    }

    public function markMerchandiserOrdered(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if (! $order->isOfferLocked()) {
            throw new \InvalidArgumentException('Lock the customer offer before placing the merchandiser order.');
        }

        if (! $order->isQuoted()) {
            throw new \InvalidArgumentException('Complete the merchandiser quote before placing the order.');
        }

        if ($order->merchandiser_ordered_at !== null) {
            return $order;
        }

        $orderedAt = Carbon::now('America/Toronto');

        return $this->orders->update($order, [
            'merchandiser_ordered_at' => $orderedAt,
            'estimated_arrival_at' => SpecialOrderEta::computeDate(
                $orderedAt,
                is_int($order->receive_delay_days) ? $order->receive_delay_days : null,
            ),
        ]);
    }

    public function markProductReceived(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->merchandiser_ordered_at === null) {
            throw new \InvalidArgumentException('Mark merchandiser ordered before marking product received.');
        }

        if ($order->product_received_at !== null) {
            return $order;
        }

        return $this->orders->update($order, [
            'product_received_at' => Carbon::now('America/Toronto'),
        ]);
    }

    public function syncEstimatedArrival(SpecialOrder $order): ?string
    {
        $eta = SpecialOrderEta::computeDate(
            $order->merchandiser_ordered_at,
            is_int($order->receive_delay_days) ? $order->receive_delay_days : null,
        );

        if ($order->estimated_arrival_at?->toDateString() === $eta) {
            return $eta;
        }

        $this->orders->update($order, ['estimated_arrival_at' => $eta]);

        return $eta;
    }

    public function reject(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->rejected_at !== null) {
            return $order;
        }

        return $this->orders->update($order, [
            'rejected_at' => Carbon::now('America/Toronto'),
            'customer_considering_at' => null,
        ]);
    }

    public function markCustomerConsidering(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->rejected_at !== null) {
            throw new \InvalidArgumentException('Revive the order before marking customer considering.');
        }

        if (! $order->isPriced()) {
            throw new \InvalidArgumentException('Set customer price and deposit before marking customer considering.');
        }

        if ($order->customer_considering_at !== null) {
            return $order;
        }

        return $this->orders->update($order, [
            'customer_considering_at' => Carbon::now('America/Toronto'),
        ]);
    }

    public function clearCustomerConsidering(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->customer_considering_at === null) {
            return $order;
        }

        return $this->orders->update($order, [
            'customer_considering_at' => null,
        ]);
    }

    public function revive(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->rejected_at === null) {
            return $order;
        }

        return $this->orders->update($order, [
            'rejected_at' => null,
        ]);
    }
}

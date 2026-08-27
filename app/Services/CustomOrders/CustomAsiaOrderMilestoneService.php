<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderEta;
use Illuminate\Support\Carbon;

final class CustomAsiaOrderMilestoneService
{
    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
    ) {}

    public function markDepositReceived(string $uuid): CustomAsiaOrder
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
        ]);
    }

    public function markMerchandiserOrdered(string $uuid): CustomAsiaOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->deposit_received_at === null) {
            throw new \InvalidArgumentException('Mark deposit received before placing the merchandiser order.');
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
            'estimated_arrival_at' => CustomAsiaOrderEta::computeDate(
                $orderedAt,
                is_int($order->receive_delay_days) ? $order->receive_delay_days : null,
            ),
        ]);
    }

    public function markProductReceived(string $uuid): CustomAsiaOrder
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

    public function syncEstimatedArrival(CustomAsiaOrder $order): ?string
    {
        $eta = CustomAsiaOrderEta::computeDate(
            $order->merchandiser_ordered_at,
            is_int($order->receive_delay_days) ? $order->receive_delay_days : null,
        );

        if ($order->estimated_arrival_at?->toDateString() === $eta) {
            return $eta;
        }

        $this->orders->update($order, ['estimated_arrival_at' => $eta]);

        return $eta;
    }

    public function reject(string $uuid): CustomAsiaOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        if ($order->rejected_at !== null) {
            return $order;
        }

        return $this->orders->update($order, [
            'rejected_at' => Carbon::now('America/Toronto'),
        ]);
    }

    public function revive(string $uuid): CustomAsiaOrder
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

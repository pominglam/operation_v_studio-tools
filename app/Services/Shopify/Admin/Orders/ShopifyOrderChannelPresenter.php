<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Models\Shopify\ShopifyOrder;
use App\Support\Shopify\Admin\Orders\ShopifyOrderAttributionSignals;

final class ShopifyOrderChannelPresenter
{
    public function __construct(
        private readonly ShopifyOrderStaffBucketClassifier $classifier,
    ) {}

    /**
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->staffByUserId() as $staff) {
            $options[] = $staff;
        }

        $extra = config('shopify.staff_order_report.extra_buckets');
        if (is_array($extra)) {
            foreach ($extra as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }
                $key = is_string($bucket['key'] ?? null) ? trim($bucket['key']) : '';
                $label = is_string($bucket['label'] ?? null) ? trim($bucket['label']) : '';
                if ($key !== '' && $label !== '') {
                    $options[] = ['key' => $key, 'label' => $label];
                }
            }
        }

        $options[] = ['key' => 'unattributed', 'label' => 'Unattributed'];

        return $options;
    }

    /**
     * @return array{key: string, label: string}
     */
    public function present(ShopifyOrder $order): array
    {
        $source = is_string($order->source_name) ? trim($order->source_name) : '';
        if ($source === '') {
            return ['key' => 'unattributed', 'label' => 'Unattributed'];
        }

        $signals = ShopifyOrderAttributionSignals::fromOrder($order);
        $key = $this->classifier->classify(
            $source,
            $order->pos_user_id !== null ? (int) $order->pos_user_id : null,
            is_string($order->channel_name) ? $order->channel_name : null,
            $this->staffByUserId(),
            $signals['tags'],
            $signals['payment_gateways'],
        );

        return $this->labelForKey($key);
    }

    /**
     * @return array{key: string, label: string}|null
     */
    public function processedBy(ShopifyOrder $order): ?array
    {
        if ($order->pos_user_id === null) {
            return null;
        }

        return $this->staffByUserId()[(string) $order->pos_user_id] ?? null;
    }

    /**
     * @return array{key: string, label: string}
     */
    public function salesChannel(ShopifyOrder $order): array
    {
        $source = is_string($order->source_name) ? trim($order->source_name) : '';
        if ($source === '') {
            return ['key' => 'unattributed', 'label' => 'Unattributed'];
        }

        $signals = ShopifyOrderAttributionSignals::fromOrder($order);
        $key = $this->classifier->salesChannelKey(
            $source,
            is_string($order->channel_name) ? $order->channel_name : null,
            $signals['tags'],
            $signals['payment_gateways'],
        );
        if ($key === 'pos' && $this->processedBy($order) === null) {
            $key = 'pos_other';
        }

        if ($key === 'pos') {
            return ['key' => 'pos', 'label' => 'POS'];
        }

        return $this->labelForKey($key);
    }

    /**
     * @return array{key: string, label: string}
     */
    private function labelForKey(string $key): array
    {
        foreach ($this->options() as $option) {
            if ($option['key'] === $key) {
                return $option;
            }
        }

        return ['key' => $key, 'label' => $key];
    }

    /**
     * @return array<string, array{key: string, label: string}>
     */
    public function staffByUserId(): array
    {
        $configured = config('shopify.staff_order_report.staff');
        if (! is_array($configured)) {
            return [];
        }

        $map = [];
        foreach ($configured as $userId => $staff) {
            if (! is_array($staff)) {
                continue;
            }
            $key = is_string($staff['key'] ?? null) ? trim($staff['key']) : '';
            $label = is_string($staff['label'] ?? null) ? trim($staff['label']) : '';
            if ($key === '' || $label === '') {
                continue;
            }
            $map[(string) $userId] = ['key' => $key, 'label' => $label];
        }

        return $map;
    }
}

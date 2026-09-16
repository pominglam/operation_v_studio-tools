<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

final class ShopifyOrderStaffBucketClassifier
{
    /**
     * @param  array<string, array{key: string, label: string}>  $staffByUserId
     * @param  list<string>  $tags
     * @param  list<string>  $paymentGatewayNames
     */
    public function classify(
        string $sourceName,
        ?int $userId,
        ?string $channelName,
        array $staffByUserId,
        array $tags = [],
        array $paymentGatewayNames = [],
    ): string {
        $channel = $this->salesChannelKey($sourceName, $channelName, $tags, $paymentGatewayNames);
        if ($channel === 'pos') {
            if ($userId !== null && isset($staffByUserId[(string) $userId])) {
                return $staffByUserId[(string) $userId]['key'];
            }

            return 'pos_other';
        }

        return $channel;
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $paymentGatewayNames
     */
    public function salesChannelKey(
        string $sourceName,
        ?string $channelName,
        array $tags = [],
        array $paymentGatewayNames = [],
    ): string {
        if ($this->isSpecialOrder($sourceName, $tags)) {
            return 'special_order';
        }
        if ($this->isNtSale($tags)) {
            return 'nt_sales';
        }
        if ($this->isCashSale($tags, $paymentGatewayNames)) {
            return 'cash_sale';
        }

        $source = strtolower(trim($sourceName));
        if ($source === 'quick_sale') {
            return 'quick_sale';
        }
        if ($source === 'web') {
            return 'online_store';
        }
        if ($this->isShopChannel($source, $channelName)) {
            return 'shop';
        }
        if ($source === 'pos') {
            return 'pos';
        }

        return 'pos_other';
    }

    /**
     * @param  list<string>  $tags
     */
    public function isSpecialOrder(string $sourceName, array $tags): bool
    {
        if (strtolower(trim($sourceName)) === 'shopify_draft_order') {
            return true;
        }

        return $this->hasAnyNormalized($tags, [
            'special-order',
            'special-deposit',
            'special-balance',
            'special_order',
        ]);
    }

    /**
     * @param  list<string>  $tags
     */
    public function isNtSale(array $tags): bool
    {
        return $this->hasAnyNormalized($tags, ['nt', 'nt-sale', 'nt_sales', 'nt-sales']);
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $paymentGatewayNames
     */
    public function isCashSale(array $tags, array $paymentGatewayNames): bool
    {
        if ($this->isNtSale($tags)) {
            return false;
        }

        if ($this->hasAnyNormalized($tags, ['cash'])) {
            return true;
        }

        foreach ($paymentGatewayNames as $gateway) {
            $name = strtolower(trim($gateway));
            if ($name === 'cash' || str_starts_with($name, 'cash ') || str_contains($name, 'cash on delivery')) {
                return true;
            }
        }

        return false;
    }

    private function isShopChannel(string $sourceName, ?string $channelName): bool
    {
        $channel = strtolower(trim((string) $channelName));
        if ($channel === 'shop') {
            return true;
        }

        return $sourceName !== ''
            && $sourceName !== 'web'
            && $sourceName !== 'pos'
            && $sourceName !== 'quick_sale'
            && $sourceName !== 'shopify_draft_order';
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $needles
     */
    private function hasAnyNormalized(array $values, array $needles): bool
    {
        $set = [];
        foreach ($values as $value) {
            $set[strtolower(trim($value))] = true;
        }

        foreach ($needles as $needle) {
            if (isset($set[$needle])) {
                return true;
            }
        }

        return false;
    }
}

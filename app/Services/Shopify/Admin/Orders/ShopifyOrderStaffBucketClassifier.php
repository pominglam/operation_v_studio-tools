<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

final class ShopifyOrderStaffBucketClassifier
{
    /**
     * @param  array<string, array{key: string, label: string}>  $staffByUserId
     */
    public function classify(string $sourceName, ?int $userId, ?string $channelName, array $staffByUserId): string
    {
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
            if ($userId !== null && isset($staffByUserId[(string) $userId])) {
                return $staffByUserId[(string) $userId]['key'];
            }

            return 'pos_other';
        }

        return 'pos_other';
    }

    private function isShopChannel(string $sourceName, ?string $channelName): bool
    {
        $channel = strtolower(trim((string) $channelName));
        if ($channel === 'shop') {
            return true;
        }

        return $sourceName !== '' && $sourceName !== 'web' && $sourceName !== 'pos' && $sourceName !== 'quick_sale';
    }
}

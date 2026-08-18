<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Support\Shopify\Admin\Orders\ShopifyOrderGraphQlStaffAttribution;

final class ShopifyOrderStaffAttributionUpsertService
{
    public function __construct(
        private readonly ShopifyOrderPosUserIdFetcher $userIdFetcher,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     * @return array{source_name: ?string, channel_name: ?string, pos_user_id: ?int}
     */
    public function attributesFromGraphQlNode(array $node): array
    {
        $sourceName = ShopifyOrderGraphQlStaffAttribution::sourceName($node);
        $channelName = ShopifyOrderGraphQlStaffAttribution::channelName($node);
        $posUserId = $this->resolvePosUserId($node, $sourceName);

        return [
            'source_name' => $sourceName,
            'channel_name' => $channelName,
            'pos_user_id' => $posUserId,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolvePosUserId(array $node, ?string $sourceName): ?int
    {
        if (strtolower(trim((string) $sourceName)) !== 'pos') {
            return null;
        }

        $legacyId = is_string($node['legacyResourceId'] ?? null) ? trim($node['legacyResourceId']) : '';
        if ($legacyId === '') {
            return null;
        }

        return $this->userIdFetcher->fetchUserId($legacyId);
    }
}

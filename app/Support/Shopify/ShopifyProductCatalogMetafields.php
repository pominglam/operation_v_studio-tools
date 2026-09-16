<?php

declare(strict_types=1);

namespace App\Support\Shopify;

use App\Models\Product;
use App\Services\Products\ProductLatestPoReceivedDateResolver;

final class ShopifyProductCatalogMetafields
{
    public const NAMESPACE = 'ovs_catalog';

    public const KEY_LATEST_PO_RECEIVED_DATE = 'latest_po_received_date';

    public function __construct(
        private readonly ProductLatestPoReceivedDateResolver $latestPoReceivedDates,
    ) {}

    /**
     * @return list<array{namespace: string, key: string, type: string, value: string}>
     */
    public function forProductSet(Product $product): array
    {
        $day = $this->latestPoReceivedDates->forProduct($product);
        if ($day === null) {
            return [];
        }

        return [[
            'namespace' => self::NAMESPACE,
            'key' => self::KEY_LATEST_PO_RECEIVED_DATE,
            'type' => 'date',
            'value' => $day,
        ]];
    }
}

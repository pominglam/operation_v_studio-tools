<?php

declare(strict_types=1);

namespace App\DAL\Customers;

use App\Models\Shopify\ShopifyCustomer;
use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\Orders\ShopifyOrderDemandEligibility;
use App\Support\Customers\ShopifyOrderIdentityRow;

final class EloquentShopifyOrderIdentityRepository implements ShopifyOrderIdentityRepository
{
    public function __construct(
        private readonly ShopifyOrderDemandEligibility $eligibility,
    ) {}

    /**
     * @return list<ShopifyOrderIdentityRow>
     */
    public function eligibleIdentityRows(): array
    {
        $query = ShopifyOrder::query();
        $this->eligibility->scopeDemandEligibleOrders($query);

        $rows = [];
        foreach ($query->orderBy('id')->get([
            'id',
            'gid',
            'legacy_numeric_id',
            'name',
            'customer_gid',
            'customer_email',
            'customer_phone',
            'subtotal_shop_amount',
            'ordered_at_shop_tz',
            'source_name',
            'channel_name',
        ]) as $order) {
            $rows[] = $this->toRow($order, true);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $gids
     * @return array<string, string>
     */
    public function customerDisplayNamesByGid(array $gids): array
    {
        if ($gids === []) {
            return [];
        }

        $names = [];
        foreach (ShopifyCustomer::query()->whereIn('gid', $gids)->get(['gid', 'display_name']) as $customer) {
            $name = is_string($customer->display_name) ? trim($customer->display_name) : '';
            if ($name !== '') {
                $names[(string) $customer->gid] = $name;
            }
        }

        return $names;
    }

    private function toRow(ShopifyOrder $order, bool $eligible): ShopifyOrderIdentityRow
    {
        $subtotal = $order->subtotal_shop_amount;

        return new ShopifyOrderIdentityRow(
            id: (int) $order->id,
            gid: (string) $order->gid,
            legacyNumericId: is_string($order->legacy_numeric_id) ? $order->legacy_numeric_id : null,
            name: is_string($order->name) ? $order->name : null,
            customerGid: is_string($order->customer_gid) ? $order->customer_gid : null,
            customerEmail: is_string($order->customer_email) ? $order->customer_email : null,
            customerPhone: is_string($order->customer_phone) ? $order->customer_phone : null,
            subtotalShopAmount: $subtotal !== null ? number_format((float) $subtotal, 2, '.', '') : null,
            orderedAt: $order->ordered_at_shop_tz,
            sourceName: is_string($order->source_name) ? $order->source_name : null,
            channelName: is_string($order->channel_name) ? $order->channel_name : null,
            eligible: $eligible,
        );
    }
}

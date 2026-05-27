<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;
use Illuminate\Database\Eloquent\Builder;

final class ShopifyOrderDemandEligibility
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function isEligibleFromGraphQlNode(array $node): bool
    {
        return $this->isEligible(
            $this->parseCancelledAt($node),
            isset($node['displayFinancialStatus']) && is_string($node['displayFinancialStatus'])
                ? $node['displayFinancialStatus']
                : null,
        );
    }

    public function isEligibleOrder(?ShopifyOrder $order): bool
    {
        if ($order === null) {
            return false;
        }

        return $this->isEligible($order->cancelled_at, $order->display_financial_status);
    }

    /**
     * @param  Builder<\App\Models\Shopify\ShopifyOrderLineItem>  $query
     * @return Builder<\App\Models\Shopify\ShopifyOrderLineItem>
     */
    public function scopeDemandEligibleLineItems(Builder $query): Builder
    {
        return $query->whereHas('order', function (Builder $orderQuery): void {
            $this->scopeDemandEligibleOrders($orderQuery);
        });
    }

    /**
     * @param  Builder<\App\Models\Shopify\ShopifyOrder>  $query
     * @return Builder<\App\Models\Shopify\ShopifyOrder>
     */
    public function scopeDemandEligibleOrders(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->where(function (Builder $financialQuery): void {
                $financialQuery
                    ->whereNull('display_financial_status')
                    ->orWhere('display_financial_status', '!=', 'VOIDED');
            });
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function parseCancelledAt(array $node): ?\Carbon\CarbonInterface
    {
        $cancelledAt = isset($node['cancelledAt']) && is_string($node['cancelledAt'])
            ? $node['cancelledAt']
            : null;

        return ShopifyGraphQlNodeParser::timestamp($cancelledAt);
    }

    private function isEligible(?\Carbon\CarbonInterface $cancelledAt, ?string $displayFinancialStatus): bool
    {
        if ($cancelledAt !== null) {
            return false;
        }

        return strtoupper(trim((string) ($displayFinancialStatus ?? ''))) !== 'VOIDED';
    }
}

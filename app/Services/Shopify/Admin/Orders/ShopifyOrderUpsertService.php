<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Models\Product;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderLineItem;
use App\Services\Shopify\Admin\Demand\ProductDemandRollupService;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class ShopifyOrderUpsertService
{
    public function __construct(
        private readonly ProductDemandRollupService $demandRollups,
        private readonly ShopifyOrderDemandEligibility $demandEligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function upsertFromGraphQlNode(array $node): ShopifyOrder
    {
        $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : null;
        if ($gid === null || $gid === '') {
            throw new \InvalidArgumentException('Shopify order node missing id.');
        }

        $orderedAtStr = isset($node['createdAt']) && is_string($node['createdAt']) ? $node['createdAt'] : null;
        $soldOn = ShopifyGraphQlNodeParser::timestamp($orderedAtStr)?->timezone('America/Toronto')->startOfDay();

        $financial = isset($node['displayFinancialStatus']) && is_string($node['displayFinancialStatus'])
            ? $node['displayFinancialStatus'] : null;
        $fulfillment = isset($node['displayFulfillmentStatus']) && is_string($node['displayFulfillmentStatus'])
            ? $node['displayFulfillmentStatus'] : null;

        $order = ShopifyOrder::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'name' => isset($node['name']) && is_string($node['name']) ? $node['name'] : null,
                'display_financial_status' => $financial,
                'display_fulfillment_status' => $fulfillment,
                'ordered_at_shop_tz' => ShopifyGraphQlNodeParser::timestamp($orderedAtStr),
                'cancelled_at' => $this->demandEligibility->parseCancelledAt($node),
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                ),
                'payload_json' => $node,
            ],
        );

        $demandEligible = $this->demandEligibility->isEligibleFromGraphQlNode($node);
        $this->syncLineItems($gid, $node, $soldOn, $demandEligible);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function syncLineItems(string $orderGid, array $node, ?CarbonInterface $soldOn, bool $demandEligible): void
    {
        $lineItems = $node['lineItems'] ?? null;
        $nodes = is_array($lineItems) && is_array($lineItems['nodes'] ?? null) ? $lineItems['nodes'] : [];

        $seenLineGids = [];
        foreach ($nodes as $lineNode) {
            if (! is_array($lineNode)) {
                continue;
            }
            $lineGid = isset($lineNode['id']) && is_string($lineNode['id']) ? $lineNode['id'] : null;
            if ($lineGid === null || $lineGid === '') {
                continue;
            }
            $seenLineGids[] = $lineGid;
            $this->upsertLineItem($orderGid, $lineGid, $lineNode, $soldOn, $demandEligible);
        }

        $stale = ShopifyOrderLineItem::query()
            ->where('order_gid', $orderGid)
            ->when($seenLineGids !== [], fn ($q) => $q->whereNotIn('line_gid', $seenLineGids))
            ->get();

        foreach ($stale as $oldLine) {
            $this->removeLineItemRollup($oldLine);
            $oldLine->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $lineNode
     */
    private function upsertLineItem(
        string $orderGid,
        string $lineGid,
        array $lineNode,
        ?CarbonInterface $soldOn,
        bool $demandEligible,
    ): void {
        $sku = $this->resolveSku($lineNode);
        $qty = isset($lineNode['quantity']) && is_numeric($lineNode['quantity']) ? (int) $lineNode['quantity'] : 0;
        $productId = $this->resolveProductId($sku);

        /** @var ShopifyOrderLineItem|null $existing */
        $existing = ShopifyOrderLineItem::query()->where('line_gid', $lineGid)->first();

        if ($existing !== null) {
            $this->removeLineItemRollup($existing);
        }

        ShopifyOrderLineItem::query()->updateOrCreate(
            ['line_gid' => $lineGid],
            [
                'order_gid' => $orderGid,
                'sku' => $sku,
                'product_id' => $productId,
                'quantity' => max(0, $qty),
                'sold_on' => $soldOn?->toDateString(),
                'payload_json' => $lineNode,
            ],
        );

        if ($demandEligible && $productId !== null && $soldOn !== null && $qty > 0) {
            $this->demandRollups->adjustShopifySold($productId, $soldOn, $qty);
        }
    }

    private function removeLineItemRollup(ShopifyOrderLineItem $line): void
    {
        if ($line->product_id === null || $line->sold_on === null || (int) $line->quantity <= 0) {
            return;
        }

        $soldOn = $line->sold_on instanceof CarbonInterface
            ? $line->sold_on
            : ($line->sold_on !== null ? Carbon::parse((string) $line->sold_on) : null);

        $this->demandRollups->adjustShopifySold(
            (int) $line->product_id,
            $soldOn,
            -((int) $line->quantity),
        );
    }

    /**
     * @param  array<string, mixed>  $lineNode
     */
    private function resolveSku(array $lineNode): ?string
    {
        $sku = isset($lineNode['sku']) && is_string($lineNode['sku']) ? trim($lineNode['sku']) : '';
        if ($sku !== '') {
            return $sku;
        }
        $variant = $lineNode['variant'] ?? null;
        if (is_array($variant)) {
            $variantSku = isset($variant['sku']) && is_string($variant['sku']) ? trim($variant['sku']) : '';
            if ($variantSku !== '') {
                return $variantSku;
            }
        }

        return null;
    }

    private function resolveProductId(?string $sku): ?int
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        /** @var int|null $id */
        $id = Product::query()->where('sku', $sku)->value('id');

        return $id !== null ? (int) $id : null;
    }
}

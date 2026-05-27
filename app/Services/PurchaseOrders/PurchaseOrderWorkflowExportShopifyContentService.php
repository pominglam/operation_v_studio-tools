<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyProductCreateFromErpService;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use App\Services\Shopify\CloudflaredTunnel;

final class PurchaseOrderWorkflowExportShopifyContentService
{
    public const string EXPORT_TYPE = 'shopify_content_no_inventory';

    public const string EXPORT_TYPE_LABEL = 'Shopify content (images + description, no inventory)';

    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductRepository $products,
        private readonly ShopifyProductCreateFromErpService $shopifyCreate,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly CloudflaredTunnel $tunnel,
    ) {}

    /**
     * @return array{
     *   export_type: string,
     *   export_type_label: string,
     *   write_scope_ok: bool,
     *   images_enabled: bool,
     *   tunnel_url: string|null,
     *   products: array<int, array<string, mixed>>,
     *   export_count: int,
     *   product_uuids: array<int, string>
     * }
     */
    public function preview(string $purchaseOrderUuid): array
    {
        $tunnelStatus = $this->tunnel->status();
        $tunnelUrl = is_string($tunnelStatus['tunnel_url'] ?? null) ? trim($tunnelStatus['tunnel_url']) : '';
        $imagesEnabled = ($tunnelStatus['running'] ?? false) === true && $tunnelUrl !== '';

        $products = $this->scope->productsForPo($purchaseOrderUuid, false);
        $rows = [];
        $exportUuids = [];

        foreach ($products as $product) {
            $handle = is_string($product->handle ?? null) ? trim($product->handle) : '';
            if ($handle !== '') {
                continue;
            }

            $hasSellingPrice = $this->hasSellingPrice($product);
            $row = [
                'product_uuid' => (string) $product->uuid,
                'sku' => (string) $product->sku,
                'description' => (string) $product->description,
                'handle' => null,
                'export_eligible' => $hasSellingPrice,
                'skip_reason' => $hasSellingPrice ? null : 'missing_selling_price',
            ];
            $rows[] = $row;

            if ($hasSellingPrice) {
                $exportUuids[] = (string) $product->uuid;
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? '')),
        );

        return [
            'export_type' => self::EXPORT_TYPE,
            'export_type_label' => self::EXPORT_TYPE_LABEL,
            'write_scope_ok' => $this->scopeGuard->hasWriteProductsScope(),
            'images_enabled' => $imagesEnabled,
            'tunnel_url' => $imagesEnabled ? $tunnelUrl : null,
            'products' => $rows,
            'export_count' => count($exportUuids),
            'product_uuids' => array_values(array_unique($exportUuids)),
        ];
    }

    /**
     * @return array{
     *   created: int,
     *   failed: int,
     *   skipped: int,
     *   images_enabled: bool,
     *   results: array<int, array<string, mixed>>,
     *   errors: array<int, array{sku:string, message:string}>
     * }
     */
    public function push(string $purchaseOrderUuid): array
    {
        $preview = $this->preview($purchaseOrderUuid);
        $uuids = $preview['product_uuids'];
        if ($uuids === []) {
            return [
                'created' => 0,
                'failed' => 0,
                'skipped' => count($preview['products']),
                'images_enabled' => (bool) ($preview['images_enabled'] ?? false),
                'results' => [],
                'errors' => [],
            ];
        }

        $this->scopeGuard->assertWriteProductsScope();

        /** @var \Illuminate\Support\Collection<int, Product> $products */
        $products = $this->products->listForShopifyContentExportByUuids($uuids);
        $tunnelUrl = is_string($preview['tunnel_url'] ?? null) ? $preview['tunnel_url'] : null;

        $usedHandles = [];
        $results = [];
        $errors = [];
        $created = 0;
        $failed = 0;

        foreach ($products as $product) {
            try {
                $results[] = $this->shopifyCreate->createFromProduct(
                    $product,
                    $tunnelUrl,
                    $usedHandles,
                    includeInventory: false,
                );
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'sku' => (string) $product->sku,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'skipped' => max(0, count($preview['products']) - count($uuids)),
            'images_enabled' => (bool) ($preview['images_enabled'] ?? false),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    private function hasSellingPrice(Product $product): bool
    {
        $price = $product->sellingPrice?->selling_price;

        return is_string($price) && trim($price) !== '';
    }
}

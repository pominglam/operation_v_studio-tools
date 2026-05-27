<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflowVerificationService
{
    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
    ) {}

    /**
     * @return array<string, array{done:bool, detail?:string}>
     */
    public function evaluate(string $purchaseOrderUuid): array
    {
        $po = $this->scope->findPoOrFail($purchaseOrderUuid);
        $allProducts = $this->scope->productsForPo($purchaseOrderUuid, false);
        $newProducts = $this->scope->productsForPo($purchaseOrderUuid, true);

        return [
            'import_po' => $this->stepImportPo($po),
            'crawl_desc_image_price' => $this->stepCrawlNewProducts($newProducts),
            'select_and_arrange_product_images' => $this->stepManualChecklist($po, 'select_and_arrange_product_images'),
            'set_selling_price' => $this->stepSellingPrice($allProducts),
            'ensure_all_products_have_barcode' => $this->stepBarcode($allProducts),
            'export_to_shopify_get_handles' => $this->stepHandlesImported($allProducts),
            'import_handle_only' => $this->stepHandlesImported($newProducts),
            'update_product_available_with_shopify_current_inventory_quantity' => [
                'done' => (bool) (($po->workflow_checklist_json['update_product_available_with_shopify_current_inventory_quantity'] ?? false)),
                'detail' => 'checked_after_apply_received',
            ],
            'mark_latest_arrival_and_published_on_shopify' => $this->stepLatestArrivalPublished($allProducts),
            'import_product_available_quantity' => ['done' => false, 'detail' => 'deferred'],
        ];
    }

    /**
     * @return array{done:bool, detail?:string}
     */
    private function stepManualChecklist(PurchaseOrder $po, string $key): array
    {
        $existing = is_array($po->workflow_checklist_json) ? $po->workflow_checklist_json : [];
        $checked = (bool) ($existing[$key] ?? false);

        return [
            'done' => $checked,
            'detail' => 'manual',
        ];
    }

    /**
     * @return array{done:bool, detail?:string}
     */
    private function stepImportPo(PurchaseOrder $po): array
    {
        $items = DB::table('purchase_order_items')
            ->where('purchase_order_id', '=', (int) $po->id)
            ->whereNotNull('product_id')
            ->count();

        return ['done' => $items > 0];
    }

    /**
     * @param  iterable<int, Product>  $newProducts
     * @return array{done:bool, detail?:string}
     */
    private function stepCrawlNewProducts(iterable $newProducts): array
    {
        $missing = [];
        foreach ($newProducts as $product) {
            if (! $this->productHasPdpDescription($product) || ! $this->productHasPdpImages($product)) {
                $missing[] = (string) $product->sku;
            }
        }

        if ($missing === []) {
            return ['done' => true];
        }

        return [
            'done' => false,
            'detail' => 'missing_pdp:'.implode(',', array_slice($missing, 0, 20)),
        ];
    }

    /**
     * @param  iterable<int, Product>  $products
     * @return array{done:bool, detail?:string}
     */
    private function stepSellingPrice(iterable $products): array
    {
        $missing = [];
        foreach ($products as $product) {
            $price = $product->sellingPrice?->selling_price;
            if (! is_string($price) || trim($price) === '') {
                $missing[] = (string) $product->sku;
            }
        }

        if ($missing === []) {
            return ['done' => true];
        }

        return [
            'done' => false,
            'detail' => 'missing_price:'.implode(',', array_slice($missing, 0, 20)),
        ];
    }

    /**
     * @param  iterable<int, Product>  $products
     * @return array{done:bool, detail?:string}
     */
    private function stepBarcode(iterable $products): array
    {
        $missing = [];
        foreach ($products as $product) {
            $barcode = is_string($product->barcode ?? null) ? trim($product->barcode) : '';
            if ($barcode === '') {
                $missing[] = (string) $product->sku;
            }
        }

        if ($missing === []) {
            return ['done' => true];
        }

        return [
            'done' => false,
            'detail' => 'missing_barcode:'.implode(',', array_slice($missing, 0, 20)),
        ];
    }

    /**
     * @param  iterable<int, Product>  $newProducts
     * @return array{done:bool, detail?:string}
     */
    private function stepHandlesImported(iterable $newProducts): array
    {
        $missing = [];
        foreach ($newProducts as $product) {
            $handle = is_string($product->handle ?? null) ? trim($product->handle) : '';
            if ($handle === '') {
                $missing[] = (string) $product->sku;
            }
        }

        if ($missing === []) {
            return ['done' => true];
        }

        return [
            'done' => false,
            'detail' => 'missing_handle:'.implode(',', array_slice($missing, 0, 20)),
        ];
    }

    /**
     * @param  iterable<int, Product>  $products
     * @return array{done:bool, detail?:string}
     */
    private function stepLatestArrivalPublished(iterable $products): array
    {
        $missing = [];
        foreach ($products as $product) {
            if (! $product->latest_arrival || ! $product->published_on_shopify) {
                $missing[] = (string) $product->sku;
            }
        }

        if ($missing === []) {
            return ['done' => true];
        }

        return [
            'done' => false,
            'detail' => 'missing_flags:'.implode(',', array_slice($missing, 0, 20)),
        ];
    }

    private function productHasPdpDescription(Product $product): bool
    {
        $preferred = is_string($product->preferred_description_source ?? null)
            ? trim($product->preferred_description_source)
            : '';
        if ($preferred !== '') {
            /** @var array<int, ProductExternalContent> $contents */
            $contents = $product->externalContents?->all() ?? [];
            foreach ($contents as $content) {
                if ($content->source === $preferred) {
                    $html = $content->description_html;

                    return is_string($html) && trim($html) !== '';
                }
            }
        }

        /** @var array<int, ProductExternalContent> $contents */
        $contents = $product->externalContents?->all() ?? [];
        foreach ($contents as $content) {
            $html = $content->description_html;
            if (is_string($html) && trim($html) !== '') {
                return true;
            }
        }

        return false;
    }

    private function productHasPdpImages(Product $product): bool
    {
        return ($product->imageAssets?->count() ?? 0) > 0;
    }
}

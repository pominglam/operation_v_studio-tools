<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflowChecklistService
{
    public const string META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED = 'select_and_arrange_product_images_deferred';

    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    /**
     * @param  array{
     *   import_po?:bool,
     *   crawl_desc_image_price?:bool,
     *   select_and_arrange_product_images?:bool,
     *   select_and_arrange_product_images_deferred?:bool,
     *   set_selling_price?:bool,
     *   ensure_all_products_have_barcode?:bool,
     *   export_to_shopify_get_handles?:bool,
     *   import_handle_only?:bool,
     *   update_product_available_with_shopify_current_inventory_quantity?:bool,
     *   import_product_available_quantity?:bool,
     *   mark_latest_arrival_and_published_on_shopify?:bool
     * } $changes
     */
    public function update(string $purchaseOrderUuid, array $changes): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $changes): PurchaseOrder {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);

            $existing = is_array($po->workflow_checklist_json) ? $po->workflow_checklist_json : [];
            $next = array_merge($this->defaults(), $existing);

            foreach ($this->defaults() as $key => $_) {
                if (! array_key_exists($key, $changes)) {
                    continue;
                }
                $next[$key] = (bool) $changes[$key];
            }

            if (array_key_exists(self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED, $changes)) {
                $next[self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED] = (bool) $changes[self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED];
            }

            if (
                array_key_exists('select_and_arrange_product_images', $changes)
                && ! (bool) $changes['select_and_arrange_product_images']
            ) {
                $next[self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED] = false;
            }

            $po->workflow_checklist_json = $next;
            $this->purchaseOrders->save($po);

            return $po;
        });
    }

    /**
     * @return array{
     *   import_po:bool,
     *   crawl_desc_image_price:bool,
     *   set_selling_price:bool,
     *   ensure_all_products_have_barcode:bool,
     *   export_to_shopify_get_handles:bool,
     *   import_handle_only:bool,
     *   update_product_available_with_shopify_current_inventory_quantity:bool,
     *   import_product_available_quantity:bool,
     *   mark_latest_arrival_and_published_on_shopify:bool
     * }
     */
    private function defaults(): array
    {
        return [
            'import_po' => false,
            'crawl_desc_image_price' => false,
            'select_and_arrange_product_images' => false,
            'set_selling_price' => false,
            'ensure_all_products_have_barcode' => false,
            'export_to_shopify_get_handles' => false,
            'import_handle_only' => false,
            'update_product_available_with_shopify_current_inventory_quantity' => false,
            'import_product_available_quantity' => false,
            'mark_latest_arrival_and_published_on_shopify' => false,
        ];
    }
}

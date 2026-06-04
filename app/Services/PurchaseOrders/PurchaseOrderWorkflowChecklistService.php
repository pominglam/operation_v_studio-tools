<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflowChecklistService
{
    public const string META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED = 'select_and_arrange_product_images_deferred';

    public const string META_CRAWL_DESC_IMAGE_PRICE_SKIPPED = 'crawl_desc_image_price_skipped';

    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderWorkflowChecklistNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(string $purchaseOrderUuid, array $changes): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $changes): PurchaseOrder {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);

            $existing = is_array($po->workflow_checklist_json) ? $po->workflow_checklist_json : [];
            $next = $this->normalizer->normalize($existing);

            foreach ($this->normalizer->defaults() as $key => $_) {
                if (! array_key_exists($key, $changes)) {
                    continue;
                }
                $next[$key] = (bool) $changes[$key];
            }

            if (array_key_exists(self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED, $changes)) {
                $next[self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED] = (bool) $changes[self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED];
            }

            if (array_key_exists(self::META_CRAWL_DESC_IMAGE_PRICE_SKIPPED, $changes)) {
                $next[self::META_CRAWL_DESC_IMAGE_PRICE_SKIPPED] = (bool) $changes[self::META_CRAWL_DESC_IMAGE_PRICE_SKIPPED];
            }

            if (
                array_key_exists('select_and_arrange_product_images', $changes)
                && ! (bool) $changes['select_and_arrange_product_images']
            ) {
                $next[self::META_SELECT_AND_ARRANGE_PRODUCT_IMAGES_DEFERRED] = false;
            }

            if (
                array_key_exists('crawl_desc_image_price', $changes)
                && ! (bool) $changes['crawl_desc_image_price']
            ) {
                $next[self::META_CRAWL_DESC_IMAGE_PRICE_SKIPPED] = false;
            }

            $po->workflow_checklist_json = $next;
            $this->purchaseOrders->save($po);

            return $po;
        });
    }
}

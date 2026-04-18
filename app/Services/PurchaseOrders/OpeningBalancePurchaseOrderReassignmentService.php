<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Products\ProductSellingPriceRepository;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class OpeningBalancePurchaseOrderReassignmentService
{
    public function __construct(
        private readonly ProductSellingPriceRepository $sellingPrices,
    ) {}

    /**
     * Moves a product's opening-balance PO items to the correct opening-balance PO based on current product.vendor.
     *
     * @return array{product_uuid:string, sku:string, from_po_uuids:array<int,string>, to_po_uuid:string|null, moved_items:int}
     */
    public function reassignForSku(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        return DB::transaction(function () use ($sku): array {
            /** @var Product|null $product */
            $product = Product::query()->where('sku', '=', $sku)->first();
            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$sku]);
            }

            $groupKey = $this->groupKeyForProduct($product);
            $poVendor = str_starts_with($groupKey, 'Stedi') ? 'Stedi' : $groupKey;
            $poNotes = "Opening balance backfill ({$groupKey}).";

            /** @var PurchaseOrder|null $targetPo */
            $targetPo = PurchaseOrder::query()
                ->where('notes', '=', $poNotes)
                ->orderByDesc('id')
                ->first();

            if ($targetPo === null) {
                $targetPo = new PurchaseOrder;
                $targetPo->vendor = $poVendor;
                $targetPo->shipping_total = '0.00';
                $targetPo->received_date = now()->toDateString();
                $targetPo->notes = $poNotes;
                $targetPo->save();
            }

            $items = PurchaseOrderItem::query()
                ->with('purchaseOrder')
                ->where('product_id', '=', (int) $product->id)
                ->whereHas('purchaseOrder', fn ($q) => $q->where('notes', 'like', 'Opening balance backfill%'))
                ->get();

            $from = [];
            $moved = 0;

            foreach ($items as $item) {
                $fromPoUuid = $item->purchaseOrder?->uuid;
                if (is_string($fromPoUuid) && $fromPoUuid !== '') {
                    $from[$fromPoUuid] = true;
                }

                if ((int) $item->purchase_order_id === (int) $targetPo->id) {
                    continue;
                }

                $item->purchase_order_id = (int) $targetPo->id;
                $item->vendor = $poVendor;
                if ($item->qty_ordered === null) {
                    $item->qty_ordered = (int) ($item->qty_received ?? 0);
                }
                if ($item->qty_shipped === null) {
                    $item->qty_shipped = (int) ($item->qty_received ?? 0);
                }
                $item->save();
                $moved++;
            }

            return [
                'product_uuid' => (string) $product->uuid,
                'sku' => (string) $product->sku,
                'from_po_uuids' => array_values(array_keys($from)),
                'to_po_uuid' => $targetPo?->uuid,
                'moved_items' => $moved,
            ];
        });
    }

    private function groupKeyForProduct(Product $product): string
    {
        $vendor = trim((string) ($product->vendor ?? ''));
        if ($vendor === '') {
            return 'Unknown';
        }

        if (strcasecmp($vendor, 'Stedi') === 0) {
            $set = array_fill_keys($this->sellingPrices->productIdsWithSellingPriceSet(), true);
            $hasSelling = array_key_exists((int) $product->id, $set);

            return $hasSelling ? 'Stedi-arrived' : 'Stedi-not-arrived';
        }

        if (strcasecmp($vendor, 'Plamod') === 0) {
            return 'Plamod';
        }

        return $vendor;
    }
}

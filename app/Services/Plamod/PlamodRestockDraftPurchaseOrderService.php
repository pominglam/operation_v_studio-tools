<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Enums\PlamodRestockSkuDecisionStatus;
use App\Models\PlamodInstockItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\PurchaseOrderShipmentMethodService;
use Illuminate\Support\Str;
use RuntimeException;

final class PlamodRestockDraftPurchaseOrderService
{
    private const string VENDOR = 'Plamod';

    public function __construct(
        private readonly PlamodRestockProposalService $proposal,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderShipmentMethodService $shipmentMethods,
    ) {}

    /**
     * @return array{
     *   purchase_order_uuid: string,
     *   existing_added: int,
     *   new_added: int,
     *   skipped_existing_on_po: int,
     *   skipped_zero_qty: int,
     *   undecided_new_skus: int,
     *   dismissed_new_skus: int
     * }
     */
    public function createDraft(): array
    {
        $proposal = $this->proposal->build(hideDismissed: true, onlyIncludedNew: false);
        $existingLines = $proposal['existing'];
        $newLines = $proposal['new_products'];

        $po = new PurchaseOrder;
        $po->uuid = (string) Str::uuid();
        $po->vendor = self::VENDOR;
        $po->vendor_currency_code = 'CAD';
        $po->notes = 'Created from PLAMOD restock proposal.';
        $po->is_done = false;
        $po = $this->purchaseOrders->create($po);
        $po->load('items');

        $existingAdded = 0;
        $skippedExisting = 0;
        $skippedZeroQty = 0;
        $existingProductIdsOnPo = [];
        $existingSkusOnPo = [];

        foreach ($existingLines as $line) {
            $qty = (int) ($line['proposed_qty'] ?? 0);
            if ($qty <= 0) {
                $skippedZeroQty++;

                continue;
            }

            /** @var Product|null $product */
            $product = Product::query()->where('uuid', '=', (string) $line['product_uuid'])->first();
            if ($product === null) {
                continue;
            }

            if (isset($existingProductIdsOnPo[(int) $product->id])) {
                $skippedExisting++;

                continue;
            }

            $instock = PlamodInstockItem::query()->where('sku', '=', $product->sku)->first();
            $unitCost = $instock?->price_stock !== null
                ? number_format((float) $instock->price_stock, 2, '.', '')
                : $this->resolveUnitCost($product, $product->sku);

            $item = new PurchaseOrderItem;
            $item->purchase_order_id = $po->id;
            $item->product_id = $product->id;
            $item->sku = $product->sku;
            $item->product_name = $product->description;
            $item->barcode = $product->barcode;
            $item->vendor = self::VENDOR;
            $item->unit_cost = $unitCost;
            $item->qty_ordered = $qty;
            $this->purchaseOrders->createItem($item);
            $existingProductIdsOnPo[(int) $product->id] = true;
            $existingSkusOnPo[$product->sku] = true;
            $existingAdded++;
        }

        $newAdded = 0;
        foreach ($newLines as $line) {
            if (($line['status'] ?? '') !== PlamodRestockSkuDecisionStatus::Included->value) {
                continue;
            }

            $qty = (int) ($line['order_qty'] ?? 0);
            if ($qty <= 0) {
                $skippedZeroQty++;

                continue;
            }

            $sku = (string) ($line['sku'] ?? '');
            if ($sku === '' || isset($existingSkusOnPo[$sku])) {
                $skippedExisting++;

                continue;
            }

            $item = new PurchaseOrderItem;
            $item->purchase_order_id = $po->id;
            $item->product_id = null;
            $item->sku = $sku;
            $item->product_name = (string) ($line['product_name'] ?? $sku);
            $item->barcode = is_string($line['barcode'] ?? null) ? $line['barcode'] : null;
            $item->vendor = self::VENDOR;
            $item->unit_cost = is_array($line['new_landed_cost'] ?? null)
                ? (string) ($line['new_landed_cost']['product'] ?? '')
                : null;
            if ($item->unit_cost === '') {
                $item->unit_cost = null;
            }
            $item->qty_ordered = $qty;
            $this->purchaseOrders->createItem($item);
            $existingSkusOnPo[$sku] = true;
            $newAdded++;
        }

        if ($existingAdded + $newAdded === 0) {
            $this->purchaseOrders->deleteItemsForPurchaseOrder((int) $po->id);
            $this->purchaseOrders->delete($po);
            throw new RuntimeException('No restock lines qualified for draft PO creation.');
        }

        $this->syncHeaderTotals($po);
        $this->shipmentMethods->applyInferredFromLineItemsIfUnset($po);

        return [
            'purchase_order_uuid' => $po->uuid,
            'existing_added' => $existingAdded,
            'new_added' => $newAdded,
            'skipped_existing_on_po' => $skippedExisting,
            'skipped_zero_qty' => $skippedZeroQty,
            'undecided_new_skus' => (int) ($proposal['meta']['undecided_new_count'] ?? 0),
            'dismissed_new_skus' => (int) ($proposal['meta']['dismissed_count'] ?? 0),
        ];
    }

    private function resolveUnitCost(Product $product, string $sku): ?string
    {
        if ($product->latest_unit_cost !== null && trim((string) $product->latest_unit_cost) !== '') {
            return number_format((float) $product->latest_unit_cost, 2, '.', '');
        }

        $instock = PlamodInstockItem::query()->where('sku', '=', $sku)->first();
        if ($instock?->price_stock !== null) {
            return number_format((float) $instock->price_stock, 2, '.', '');
        }

        return null;
    }

    private function syncHeaderTotals(PurchaseOrder $po): void
    {
        $po->load('items');
        $productTotal = 0.0;
        foreach ($po->items as $item) {
            $qty = (int) ($item->qty_ordered ?? 0);
            $unit = $item->unit_cost !== null ? (float) $item->unit_cost : 0.0;
            $productTotal += $qty * $unit;
        }

        $po->product_total = number_format($productTotal, 2, '.', '');
        $shippingPercent = app(PlamodRestockSettingsService::class)->get()['shipping_percent'];
        $po->shipping_total = number_format($productTotal * ($shippingPercent / 100), 2, '.', '');
        $this->purchaseOrders->save($po);
    }
}

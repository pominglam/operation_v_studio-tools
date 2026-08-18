<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentInput;
use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentPreview;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCombinedPayment;
use App\Models\PurchaseOrderCombinedPaymentLine;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductLatestCostCacheService;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderCombinedPaymentService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderCombinedPaymentPreviewService $previewer,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    public function create(PurchaseOrderCombinedPaymentInput $input): PurchaseOrderCombinedPaymentPreview
    {
        return DB::transaction(function () use ($input): PurchaseOrderCombinedPaymentPreview {
            $preview = $this->previewer->preview($input, true);
            $purchaseOrders = $this->purchaseOrders->findManyForCombinedPayment(
                $input->purchaseOrderIds,
                true,
            )->keyBy('uuid');
            $payment = $this->createPayment($preview);
            $skus = [];

            foreach ($preview->allocations as $allocation) {
                /** @var PurchaseOrder $po */
                $po = $purchaseOrders->get($allocation['purchase_order_id']);
                $this->applyAllocation($po, $allocation, $preview);
                $this->createPaymentLine($payment, $po, $allocation, $preview->includesShipping);
                $skus = [...$skus, ...$po->items->pluck('sku')->all()];
            }

            $this->latestCosts->recomputeForSkus(array_values(array_unique($skus)));

            return new PurchaseOrderCombinedPaymentPreview(
                id: (string) $payment->uuid,
                vendorCurrencyCode: $preview->vendorCurrencyCode,
                vendorTotal: $preview->vendorTotal,
                totalPaidCad: $preview->totalPaidCad,
                fxRateToCad: $preview->fxRateToCad,
                includesShipping: $preview->includesShipping,
                allocations: $preview->allocations,
            );
        });
    }

    private function createPayment(PurchaseOrderCombinedPaymentPreview $preview): PurchaseOrderCombinedPayment
    {
        return $this->purchaseOrders->createCombinedPayment(new PurchaseOrderCombinedPayment([
            'vendor_currency_code' => $preview->vendorCurrencyCode,
            'vendor_total' => $preview->vendorTotal,
            'total_paid_cad' => $preview->totalPaidCad,
            'fx_rate_to_cad' => $preview->fxRateToCad,
            'includes_shipping' => $preview->includesShipping,
        ]));
    }

    /**
     * @param  array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null,
     *   fx_rate_to_cad: string
     * }  $allocation
     */
    private function applyAllocation(
        PurchaseOrder $po,
        array $allocation,
        PurchaseOrderCombinedPaymentPreview $preview,
    ): void {
        $po->product_total = $allocation['product_total_cad'];
        if ($preview->includesShipping) {
            $po->shipping_total = $allocation['shipping_total_cad'];
        }
        $po->fx_rate_to_cad = $allocation['fx_rate_to_cad'];
        $this->purchaseOrders->save($po);

        foreach ($po->items as $item) {
            $this->convertItemToCad($item, $allocation['fx_rate_to_cad']);
        }
    }

    private function convertItemToCad(PurchaseOrderItem $item, string $fxRateToCad): void
    {
        $vendorUnit = $item->vendor_unit_cost !== null ? trim((string) $item->vendor_unit_cost) : '';
        if ($vendorUnit === '' || ! is_numeric($vendorUnit)) {
            return;
        }

        $item->unit_cost = $this->multiplyRounded($vendorUnit, $fxRateToCad);
        $this->purchaseOrders->saveItem($item);
    }

    /**
     * @param  array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null,
     *   fx_rate_to_cad: string
     * }  $allocation
     */
    private function createPaymentLine(
        PurchaseOrderCombinedPayment $payment,
        PurchaseOrder $po,
        array $allocation,
        bool $includesShipping,
    ): void {
        $this->purchaseOrders->createCombinedPaymentLine(new PurchaseOrderCombinedPaymentLine([
            'purchase_order_combined_payment_id' => $payment->id,
            'purchase_order_id' => $po->id,
            'vendor_product_total' => $allocation['vendor_product_total'],
            'vendor_shipping_total' => $includesShipping
                ? $allocation['vendor_shipping_total']
                : null,
            'product_total_cad' => $allocation['product_total_cad'],
            'shipping_total_cad' => $includesShipping
                ? $allocation['shipping_total_cad']
                : null,
        ]));
    }

    private function multiplyRounded(string $value, string $rate): string
    {
        if (! extension_loaded('bcmath')) {
            return number_format(round(((float) $value) * ((float) $rate), 2), 2, '.', '');
        }

        $raw = bcmul($value, $rate, 4);

        return bcadd($raw, '0.005', 2);
    }
}

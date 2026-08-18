<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentInput;
use App\DTOs\PurchaseOrders\PurchaseOrderCombinedPaymentPreview;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderCombinedPaymentException;
use Illuminate\Support\Collection;

final class PurchaseOrderCombinedPaymentPreviewService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    public function preview(
        PurchaseOrderCombinedPaymentInput $input,
        bool $lockForUpdate = false,
    ): PurchaseOrderCombinedPaymentPreview {
        $purchaseOrders = $this->purchaseOrders->findManyForCombinedPayment(
            $input->purchaseOrderIds,
            $lockForUpdate,
        );
        $currency = $this->validatePurchaseOrders($purchaseOrders, $input);
        $vendorTotalCents = $this->vendorTotalCents($purchaseOrders, $input->includesShipping);
        $totalPaidCents = $this->moneyToCents($input->totalPaidCad);
        $fxRateToCad = $this->divideToSix($totalPaidCents, $vendorTotalCents);
        $splitCadCents = $this->splitCadCents($input, $totalPaidCents);
        $allocations = $input->allocations === null
            ? ($splitCadCents === null
                ? $this->allocate(
                    $purchaseOrders,
                    $input,
                    $vendorTotalCents,
                    $totalPaidCents,
                    $fxRateToCad,
                )
                : $this->allocateSplit($purchaseOrders, $splitCadCents))
            : $this->manualAllocations($purchaseOrders, $input, $totalPaidCents, $splitCadCents);

        return new PurchaseOrderCombinedPaymentPreview(
            id: null,
            vendorCurrencyCode: $currency,
            vendorTotal: $this->centsToMoney($vendorTotalCents),
            totalPaidCad: $this->centsToMoney($totalPaidCents),
            fxRateToCad: $fxRateToCad,
            includesShipping: $input->includesShipping,
            allocations: $allocations,
        );
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     */
    private function validatePurchaseOrders(
        Collection $purchaseOrders,
        PurchaseOrderCombinedPaymentInput $input,
    ): string {
        if ($purchaseOrders->count() !== count($input->purchaseOrderIds)) {
            throw new PurchaseOrderCombinedPaymentException('One or more selected purchase orders were not found.');
        }

        $currencies = $purchaseOrders
            ->map(static fn (PurchaseOrder $po): string => strtoupper(trim((string) $po->vendor_currency_code)))
            ->unique()
            ->values();
        if ($currencies->count() !== 1 || $currencies->first() === 'CAD' || $currencies->first() === '') {
            throw new PurchaseOrderCombinedPaymentException('Selected purchase orders must use the same non-CAD vendor currency.');
        }

        foreach ($purchaseOrders as $po) {
            $this->validatePurchaseOrder($po, $input->includesShipping);
        }

        return (string) $currencies->first();
    }

    private function validatePurchaseOrder(PurchaseOrder $po, bool $includesShipping): void
    {
        if ($po->combinedPaymentLine !== null) {
            throw new PurchaseOrderCombinedPaymentException('One or more selected purchase orders already have a combined payment.');
        }
        foreach ($po->items as $item) {
            if ((int) ($item->qty_received ?? 0) > 0 || $item->lots->isNotEmpty()) {
                throw new PurchaseOrderCombinedPaymentException(
                    'Combined payment cannot change a PO after inventory has been received.',
                );
            }
        }
        $product = $po->vendor_product_total !== null ? trim((string) $po->vendor_product_total) : '';
        if ($product === '' || ! is_numeric($product) || (float) $product <= 0) {
            throw new PurchaseOrderCombinedPaymentException(
                'Every selected PO must have a vendor-currency product total greater than zero.',
            );
        }
        $shipping = $po->vendor_shipping_total !== null ? trim((string) $po->vendor_shipping_total) : '';
        if ($includesShipping && ($shipping === '' || ! is_numeric($shipping) || (float) $shipping < 0)) {
            throw new PurchaseOrderCombinedPaymentException(
                'Every selected PO needs a vendor-currency shipping total when shipping is included.',
            );
        }
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     */
    private function vendorTotalCents(Collection $purchaseOrders, bool $includesShipping): int
    {
        $total = 0;
        foreach ($purchaseOrders as $po) {
            $total += $this->moneyToCents((string) $po->vendor_product_total);
            if ($includesShipping) {
                $total += $this->moneyToCents((string) $po->vendor_shipping_total);
            }
        }
        if ($total <= 0) {
            throw new PurchaseOrderCombinedPaymentException('Combined vendor total must be greater than zero.');
        }

        return $total;
    }

    /**
     * @return array{product: int, shipping: int}|null
     */
    private function splitCadCents(
        PurchaseOrderCombinedPaymentInput $input,
        int $totalPaidCents,
    ): ?array {
        if ($input->productPaidCad === null && $input->shippingPaidCad === null) {
            return null;
        }
        if (! $input->includesShipping
            || $input->productPaidCad === null
            || $input->shippingPaidCad === null) {
            throw new PurchaseOrderCombinedPaymentException(
                'A combined product and shipping split requires shipping to be included.',
            );
        }

        $split = [
            'product' => $this->moneyToCents($input->productPaidCad),
            'shipping' => $this->moneyToCents($input->shippingPaidCad),
        ];
        if ($totalPaidCents !== $split['product'] + $split['shipping']) {
            throw new PurchaseOrderCombinedPaymentException(
                'Combined CAD product and shipping amounts must equal total paid.',
            );
        }

        return $split;
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     * @return array<int, array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null,
     *   fx_rate_to_cad: string
     * }>
     */
    private function allocate(
        Collection $purchaseOrders,
        PurchaseOrderCombinedPaymentInput $input,
        int $vendorTotalCents,
        int $totalPaidCents,
        string $fxRateToCad,
    ): array {
        $componentCount = $purchaseOrders->count() * ($input->includesShipping ? 2 : 1);
        $componentIndex = 0;
        $remainingCadCents = $totalPaidCents;
        $rows = [];

        foreach ($purchaseOrders as $po) {
            $productVendorCents = $this->moneyToCents((string) $po->vendor_product_total);
            $productCadCents = $this->allocateComponent(
                $totalPaidCents,
                $productVendorCents,
                $vendorTotalCents,
                $remainingCadCents,
                ++$componentIndex === $componentCount,
            );
            $shippingCad = $this->shippingCadAllocation(
                $po,
                $input,
                $vendorTotalCents,
                $totalPaidCents,
                $remainingCadCents,
                $componentIndex,
                $componentCount,
            );
            if ($input->includesShipping) {
                $componentIndex++;
            }
            $rows[] = $this->allocationRow($po, $productCadCents, $shippingCad, $fxRateToCad);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     * @param  array{product: int, shipping: int}  $splitCadCents
     * @return array<int, array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null,
     *   fx_rate_to_cad: string
     * }>
     */
    private function allocateSplit(Collection $purchaseOrders, array $splitCadCents): array
    {
        $productVendorTotal = $this->componentVendorTotalCents(
            $purchaseOrders,
            'vendor_product_total',
        );
        $shippingVendorTotal = $this->componentVendorTotalCents(
            $purchaseOrders,
            'vendor_shipping_total',
        );
        if ($shippingVendorTotal === 0 && $splitCadCents['shipping'] > 0) {
            throw new PurchaseOrderCombinedPaymentException(
                'Combined vendor shipping total must be greater than zero.',
            );
        }

        $productAllocations = $this->allocatePool(
            $purchaseOrders,
            'vendor_product_total',
            $productVendorTotal,
            $splitCadCents['product'],
        );
        $shippingAllocations = $shippingVendorTotal === 0
            ? $purchaseOrders->mapWithKeys(
                static fn (PurchaseOrder $po): array => [(string) $po->uuid => 0],
            )->all()
            : $this->allocatePool(
                $purchaseOrders,
                'vendor_shipping_total',
                $shippingVendorTotal,
                $splitCadCents['shipping'],
            );
        $productFxRate = $this->divideToSix($splitCadCents['product'], $productVendorTotal);

        return $purchaseOrders->map(fn (PurchaseOrder $po): array => $this->allocationRow(
            $po,
            $productAllocations[(string) $po->uuid],
            $shippingAllocations[(string) $po->uuid],
            $productFxRate,
        ))->values()->all();
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     * @return array<string, int>
     */
    private function allocatePool(
        Collection $purchaseOrders,
        string $vendorColumn,
        int $vendorTotalCents,
        int $cadTotalCents,
    ): array {
        $remaining = $cadTotalCents;
        $lastIndex = $purchaseOrders->count() - 1;
        $allocations = [];
        foreach ($purchaseOrders->values() as $index => $po) {
            $allocations[(string) $po->uuid] = $this->allocateComponent(
                $cadTotalCents,
                $this->moneyToCents((string) $po->{$vendorColumn}),
                $vendorTotalCents,
                $remaining,
                $index === $lastIndex,
            );
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     */
    private function componentVendorTotalCents(Collection $purchaseOrders, string $column): int
    {
        return $purchaseOrders->sum(
            fn (PurchaseOrder $po): int => $this->moneyToCents((string) $po->{$column}),
        );
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     * @return array<int, array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null,
     *   fx_rate_to_cad: string
     * }>
     */
    private function manualAllocations(
        Collection $purchaseOrders,
        PurchaseOrderCombinedPaymentInput $input,
        int $totalPaidCents,
        ?array $splitCadCents,
    ): array {
        $allocations = collect($input->allocations)->keyBy('purchase_order_id');
        $selectedIds = $purchaseOrders->pluck('uuid')->map(static fn (mixed $id): string => (string) $id);
        if ($allocations->count() !== $purchaseOrders->count()
            || $selectedIds->diff($allocations->keys())->isNotEmpty()
            || $allocations->keys()->diff($selectedIds)->isNotEmpty()) {
            throw new PurchaseOrderCombinedPaymentException(
                'Manual CAD allocations must include each selected PO exactly once.',
            );
        }

        $allocatedCents = 0;
        $allocatedProductCents = 0;
        $allocatedShippingCents = 0;
        $rows = [];
        foreach ($purchaseOrders as $po) {
            /** @var array{
             *   purchase_order_id: string,
             *   product_total_cad: string,
             *   shipping_total_cad: string|null
             * } $allocation
             */
            $allocation = $allocations->get((string) $po->uuid);
            $productCadCents = $this->moneyToCents($allocation['product_total_cad']);
            $shippingCadCents = $input->includesShipping
                ? $this->moneyToCents((string) $allocation['shipping_total_cad'])
                : ($po->shipping_total !== null
                    ? $this->moneyToCents((string) $po->shipping_total)
                    : null);
            $allocatedCents += $productCadCents;
            $allocatedProductCents += $productCadCents;
            if ($input->includesShipping) {
                $allocatedCents += $shippingCadCents ?? 0;
                $allocatedShippingCents += $shippingCadCents ?? 0;
            }
            $vendorProductCents = $this->moneyToCents((string) $po->vendor_product_total);
            $rows[] = $this->allocationRow(
                $po,
                $productCadCents,
                $shippingCadCents,
                $this->divideToSix($productCadCents, $vendorProductCents),
            );
        }

        if ($allocatedCents !== $totalPaidCents) {
            throw new PurchaseOrderCombinedPaymentException(
                'Manual CAD allocations must add up exactly to total paid.',
            );
        }
        if ($splitCadCents !== null
            && ($allocatedProductCents !== $splitCadCents['product']
                || $allocatedShippingCents !== $splitCadCents['shipping'])) {
            throw new PurchaseOrderCombinedPaymentException(
                'Manual CAD allocations must match the combined product and shipping amounts.',
            );
        }

        return $rows;
    }

    private function shippingCadAllocation(
        PurchaseOrder $po,
        PurchaseOrderCombinedPaymentInput $input,
        int $vendorTotalCents,
        int $totalPaidCents,
        int &$remainingCadCents,
        int $componentIndex,
        int $componentCount,
    ): ?int {
        if (! $input->includesShipping) {
            return $po->shipping_total !== null
                ? $this->moneyToCents((string) $po->shipping_total)
                : null;
        }

        return $this->allocateComponent(
            $totalPaidCents,
            $this->moneyToCents((string) $po->vendor_shipping_total),
            $vendorTotalCents,
            $remainingCadCents,
            $componentIndex + 1 === $componentCount,
        );
    }

    private function allocateComponent(
        int $totalPaidCents,
        int $weightCents,
        int $totalWeightCents,
        int &$remainingCadCents,
        bool $isLast,
    ): int {
        $allocated = $isLast
            ? $remainingCadCents
            : (int) round(($totalPaidCents * $weightCents) / $totalWeightCents, 0, PHP_ROUND_HALF_UP);
        $remainingCadCents -= $allocated;

        return $allocated;
    }

    /**
     * @return array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null,
     *   fx_rate_to_cad: string
     * }
     */
    private function allocationRow(
        PurchaseOrder $po,
        int $productCadCents,
        ?int $shippingCadCents,
        string $fxRateToCad,
    ): array {
        return [
            'purchase_order_id' => (string) $po->uuid,
            'vendor' => (string) $po->vendor,
            'supplier_order_id' => $po->supplier_order_id,
            'shipment_method' => $po->shipment_method,
            'vendor_product_total' => number_format((float) $po->vendor_product_total, 2, '.', ''),
            'vendor_shipping_total' => $po->vendor_shipping_total !== null
                ? number_format((float) $po->vendor_shipping_total, 2, '.', '')
                : null,
            'product_total_cad' => $this->centsToMoney($productCadCents),
            'shipping_total_cad' => $shippingCadCents !== null
                ? $this->centsToMoney($shippingCadCents)
                : null,
            'fx_rate_to_cad' => $fxRateToCad,
        ];
    }

    private function moneyToCents(string $value): int
    {
        return (int) round(((float) trim($value)) * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function divideToSix(int $cadCents, int $vendorCents): string
    {
        if (extension_loaded('bcmath')) {
            return bcdiv((string) $cadCents, (string) $vendorCents, 6);
        }

        return number_format($cadCents / $vendorCents, 6, '.', '');
    }
}

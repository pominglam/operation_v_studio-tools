<?php

declare(strict_types=1);

namespace App\DTOs\PurchaseOrders;

final readonly class PurchaseOrderCombinedPaymentPreview
{
    /**
     * @param  array<int, array{
     *   purchase_order_id: string,
     *   vendor: string,
     *   supplier_order_id: string|null,
     *   shipment_method: string|null,
     *   vendor_product_total: string,
     *   vendor_shipping_total: string|null,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null
     * }>  $allocations
     */
    public function __construct(
        public ?string $id,
        public string $vendorCurrencyCode,
        public string $vendorTotal,
        public string $totalPaidCad,
        public string $fxRateToCad,
        public bool $includesShipping,
        public array $allocations,
    ) {}

    /**
     * @return array{
     *   id: string|null,
     *   vendor_currency_code: string,
     *   vendor_total: string,
     *   total_paid_cad: string,
     *   fx_rate_to_cad: string,
     *   includes_shipping: bool,
     *   allocations: array<int, array<string, string|null>>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vendor_currency_code' => $this->vendorCurrencyCode,
            'vendor_total' => $this->vendorTotal,
            'total_paid_cad' => $this->totalPaidCad,
            'fx_rate_to_cad' => $this->fxRateToCad,
            'includes_shipping' => $this->includesShipping,
            'allocations' => $this->allocations,
        ];
    }
}

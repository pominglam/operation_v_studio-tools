<?php

declare(strict_types=1);

namespace App\DTOs\PurchaseOrders;

final readonly class PurchaseOrderCombinedPaymentInput
{
    /**
     * @param  array<int, string>  $purchaseOrderIds
     * @param  array<int, array{
     *   purchase_order_id: string,
     *   product_total_cad: string,
     *   shipping_total_cad: string|null
     * }>|null  $allocations
     */
    public function __construct(
        public array $purchaseOrderIds,
        public string $totalPaidCad,
        public bool $includesShipping,
        public ?array $allocations = null,
        public ?string $productPaidCad = null,
        public ?string $shippingPaidCad = null,
    ) {}

    /**
     * @param  array{
     *   purchase_order_ids: array<int, string>,
     *   total_paid_cad: int|float|string,
     *   includes_shipping: bool,
     *   product_paid_cad?: int|float|string|null,
     *   shipping_paid_cad?: int|float|string|null,
     *   allocations?: array<int, array{
     *     purchase_order_id: string,
     *     product_total_cad: int|float|string,
     *     shipping_total_cad?: int|float|string|null
     *   }>
     * }  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            purchaseOrderIds: array_values($validated['purchase_order_ids']),
            totalPaidCad: number_format((float) $validated['total_paid_cad'], 2, '.', ''),
            includesShipping: $validated['includes_shipping'],
            allocations: isset($validated['allocations'])
                ? array_map(
                    static fn (array $allocation): array => [
                        'purchase_order_id' => $allocation['purchase_order_id'],
                        'product_total_cad' => number_format(
                            (float) $allocation['product_total_cad'],
                            2,
                            '.',
                            '',
                        ),
                        'shipping_total_cad' => isset($allocation['shipping_total_cad'])
                            ? number_format((float) $allocation['shipping_total_cad'], 2, '.', '')
                            : null,
                    ],
                    array_values($validated['allocations']),
                )
                : null,
            productPaidCad: isset($validated['product_paid_cad'])
                ? number_format((float) $validated['product_paid_cad'], 2, '.', '')
                : null,
            shippingPaidCad: isset($validated['shipping_paid_cad'])
                ? number_format((float) $validated['shipping_paid_cad'], 2, '.', '')
                : null,
        );
    }
}

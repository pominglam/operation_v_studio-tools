<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrders\PurchaseOrderQueryService;
use App\Support\Products\ProductListFilterCatalog;

final class ProductFilterOptionsService
{
    public function __construct(
        private readonly ProductsQueryService $products,
        private readonly PurchaseOrderQueryService $purchaseOrders,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            ...$this->distinctFacets(),
            ...$this->enumFacets(),
            'empty_fields' => $this->products->emptyCanonicalFields(),
            'purchase_orders' => $this->purchaseOrdersForFilter(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function distinctFacets(): array
    {
        return [
            'main_types' => $this->products->distinctMainTypes(),
            'types' => $this->products->distinctTypes(),
            'departments' => $this->products->distinctDepartments(),
            'manufacturers' => $this->products->distinctManufacturers(),
            'franchises' => $this->products->distinctFranchises(),
            'product_lines' => $this->products->distinctProductLines(),
            'sublines' => $this->products->distinctSublines(),
            'vendors' => $this->products->distinctVendors(),
            'grades' => $this->products->distinctGrades(),
            'scales' => $this->products->distinctScales(),
            'series' => $this->products->distinctSeries(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function enumFacets(): array
    {
        return [
            'missing_info' => ProductListFilterCatalog::missingInfo(),
            'product_flags' => ProductListFilterCatalog::productFlags(),
            'shipment_methods' => ProductListFilterCatalog::shipmentMethods(),
            'ready' => ProductListFilterCatalog::ready(),
            'archived' => ProductListFilterCatalog::archived(),
            'store_preorder' => ProductListFilterCatalog::storePreorder(),
            'published' => ProductListFilterCatalog::published(),
            'po_novelty' => ProductListFilterCatalog::poNovelty(),
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     vendor: string,
     *     created_at: string|null,
     *     ordered_date: string|null,
     *     estimated_arrival_date: string|null,
     *     received_date: string|null,
     *     counts: array{items: int}
     * }>
     */
    private function purchaseOrdersForFilter(): array
    {
        return $this->purchaseOrders->listForProductFilter()
            ->map(static function (PurchaseOrder $po): array {
                return [
                    'id' => $po->uuid,
                    'vendor' => $po->vendor,
                    'created_at' => $po->created_at?->toISOString(),
                    'ordered_date' => $po->ordered_date?->toDateString(),
                    'estimated_arrival_date' => $po->estimated_arrival_date?->toDateString(),
                    'received_date' => $po->received_date?->toDateString(),
                    'counts' => [
                        'items' => is_numeric($po->getAttribute('items_count'))
                            ? (int) $po->getAttribute('items_count')
                            : 0,
                    ],
                ];
            })
            ->values()
            ->all();
    }
}

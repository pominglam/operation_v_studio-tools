<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use Illuminate\Support\Facades\DB;

final class ProductSellingPriceHistoryQueryService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly ProductRepository $products,
    ) {}

    /**
     * @return array<int, array{
     *   id: int,
     *   product_uuid: string,
     *   sku: string,
     *   description: string|null,
     *   previous_price: string|null,
     *   new_price: string|null,
     *   currency: string,
     *   source: string,
     *   created_at: string
     * }>
     */
    public function listForPurchaseOrder(string $purchaseOrderUuid, int $limit = 200): array
    {
        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        $limit = max(1, min(500, $limit));

        $rows = DB::table('product_selling_price_history as h')
            ->join('products as p', 'p.id', '=', 'h.product_id')
            ->where('h.purchase_order_id', '=', (int) $po->id)
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->limit($limit)
            ->get([
                'h.id',
                'h.product_uuid',
                'p.sku',
                'p.description',
                'h.previous_price',
                'h.new_price',
                'h.currency',
                'h.source',
                'h.created_at',
            ]);

        return $rows->map(fn (object $row): array => $this->mapRow($row))->all();
    }

    /**
     * @return array<int, array{
     *   id: int,
     *   product_uuid: string,
     *   previous_price: string|null,
     *   new_price: string|null,
     *   currency: string,
     *   source: string,
     *   purchase_order_uuid: string|null,
     *   created_at: string
     * }>
     */
    public function listForProduct(string $productUuid, int $limit = 50): array
    {
        $product = $this->products->findByUuidOrFail($productUuid);
        $limit = max(1, min(200, $limit));

        $rows = DB::table('product_selling_price_history as h')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'h.purchase_order_id')
            ->where('h.product_id', '=', (int) $product->id)
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->limit($limit)
            ->get([
                'h.id',
                'h.product_uuid',
                'h.previous_price',
                'h.new_price',
                'h.currency',
                'h.source',
                'po.uuid as purchase_order_uuid',
                'h.created_at',
            ]);

        return $rows->map(function (object $row): array {
            return [
                'id' => (int) $row->id,
                'product_uuid' => (string) $row->product_uuid,
                'previous_price' => $this->money2($row->previous_price),
                'new_price' => $this->money2($row->new_price),
                'currency' => (string) $row->currency,
                'source' => (string) $row->source,
                'purchase_order_uuid' => $row->purchase_order_uuid !== null
                    ? (string) $row->purchase_order_uuid
                    : null,
                'created_at' => (string) $row->created_at,
            ];
        })->all();
    }

    /**
     * @return array{
     *   id: int,
     *   product_uuid: string,
     *   sku: string,
     *   description: string|null,
     *   previous_price: string|null,
     *   new_price: string|null,
     *   currency: string,
     *   source: string,
     *   created_at: string
     * }
     */
    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'product_uuid' => (string) $row->product_uuid,
            'sku' => (string) $row->sku,
            'description' => $row->description !== null ? (string) $row->description : null,
            'previous_price' => $this->money2($row->previous_price),
            'new_price' => $this->money2($row->new_price),
            'currency' => (string) $row->currency,
            'source' => (string) $row->source,
            'created_at' => (string) $row->created_at,
        ];
    }

    private function money2(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }
}

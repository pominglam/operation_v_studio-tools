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
        $collapsed = $this->collapseToNetChangePerProduct(
            $this->historyRowsForPurchaseOrder((int) $po->id),
        );
        $visible = array_values(array_filter(
            $collapsed,
            fn (array $row): bool => $this->isVisiblePoChange($row),
        ));
        $this->sortNewestFirst($visible);

        return array_slice($visible, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function historyRowsForPurchaseOrder(int $purchaseOrderId): array
    {
        $rows = DB::table('product_selling_price_history as h')
            ->join('products as p', 'p.id', '=', 'h.product_id')
            ->where('h.purchase_order_id', '=', $purchaseOrderId)
            ->orderBy('h.created_at')
            ->orderBy('h.id')
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function collapseToNetChangePerProduct(array $rows): array
    {
        $byProduct = [];

        foreach ($rows as $row) {
            $productUuid = (string) $row['product_uuid'];
            if (! isset($byProduct[$productUuid])) {
                $byProduct[$productUuid] = $row;

                continue;
            }

            $byProduct[$productUuid]['new_price'] = $row['new_price'];
            $byProduct[$productUuid]['currency'] = $row['currency'];
            $byProduct[$productUuid]['source'] = $row['source'];
            $byProduct[$productUuid]['created_at'] = $row['created_at'];
            $byProduct[$productUuid]['id'] = $row['id'];
        }

        return array_values($byProduct);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sortNewestFirst(array &$rows): void
    {
        usort(
            $rows,
            static function (array $left, array $right): int {
                $timeCmp = strcmp((string) $right['created_at'], (string) $left['created_at']);
                if ($timeCmp !== 0) {
                    return $timeCmp;
                }

                return ((int) $right['id']) <=> ((int) $left['id']);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isVisiblePoChange(array $row): bool
    {
        $previous = is_string($row['previous_price'] ?? null) ? $row['previous_price'] : null;
        $next = is_string($row['new_price'] ?? null) ? $row['new_price'] : null;
        if ($next === null) {
            return false;
        }

        if ($previous === null) {
            return true;
        }

        return abs((int) round((float) $previous * 100) - (int) round((float) $next * 100)) > 100;
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

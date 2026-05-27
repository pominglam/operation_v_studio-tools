<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderProductScopeService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    public function findPoOrFail(string $purchaseOrderUuid): PurchaseOrder
    {
        return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
    }

    /**
     * @return array<int, int>
     */
    public function productIdsForPo(string $purchaseOrderUuid): array
    {
        return $this->purchaseOrders->listProductIdsByUuid($purchaseOrderUuid);
    }

    /**
     * @return array<int, int>
     */
    public function newProductIdsForPo(string $purchaseOrderUuid): array
    {
        $purchaseOrderUuid = trim($purchaseOrderUuid);
        if ($purchaseOrderUuid === '') {
            return [];
        }

        /** @var array<int, int|string|null> $rows */
        $rows = DB::table('products as p')
            ->join('purchase_order_items as poi', 'poi.product_id', '=', 'p.id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.uuid', '=', $purchaseOrderUuid)
            ->whereRaw(
                '(select min(poi_all.purchase_order_id) from purchase_order_items poi_all where poi_all.product_id = p.id) = po.id',
            )
            ->pluck('p.id')
            ->all();

        $out = [];
        foreach ($rows as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return Collection<int, Product>
     */
    public function productsForPo(string $purchaseOrderUuid, bool $newOnly = false): Collection
    {
        $ids = $newOnly
            ? $this->newProductIdsForPo($purchaseOrderUuid)
            : $this->productIdsForPo($purchaseOrderUuid);

        if ($ids === []) {
            return collect();
        }

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', $ids)
            ->with(['sellingPrice', 'imageAssets', 'externalContents'])
            ->orderBy('sku')
            ->get();

        return $products;
    }

    /**
     * @return array<int, string>
     */
    public function productUuidsForPo(string $purchaseOrderUuid, bool $newOnly = false): array
    {
        return $this->productsForPo($purchaseOrderUuid, $newOnly)
            ->pluck('uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->filter(static fn (string $uuid): bool => trim($uuid) !== '')
            ->values()
            ->all();
    }
}

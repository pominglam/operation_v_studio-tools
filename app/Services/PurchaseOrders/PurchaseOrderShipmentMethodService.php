<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderShipmentMethodService
{
    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $method = strtolower(trim($value));
        if ($method === '') {
            return null;
        }

        return in_array($method, ['air', 'sea'], true) ? $method : null;
    }

    /**
     * @param  iterable<int, Product>  $products
     */
    public function inferFromProducts(iterable $products): ?string
    {
        /** @var array<string, true> $methods */
        $methods = [];

        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $method = $this->normalize($product->shipment_method);
            if ($method !== null) {
                $methods[$method] = true;
            }
        }

        $keys = array_keys($methods);

        return count($keys) === 1 ? $keys[0] : null;
    }

    public function inferFromPurchaseOrderId(int $purchaseOrderId): ?string
    {
        if ($purchaseOrderId <= 0) {
            return null;
        }

        $rows = DB::table('purchase_order_items as poi')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->where('poi.purchase_order_id', $purchaseOrderId)
            ->whereIn('p.shipment_method', ['air', 'sea'])
            ->select(['p.shipment_method'])
            ->distinct()
            ->get();

        /** @var array<int, string> $methods */
        $methods = [];
        foreach ($rows as $row) {
            $method = $this->normalize((string) $row->shipment_method);
            if ($method !== null) {
                $methods[] = $method;
            }
        }

        $methods = array_values(array_unique($methods));

        return count($methods) === 1 ? $methods[0] : null;
    }

    public function applyInferredFromLineItemsIfUnset(PurchaseOrder $po): void
    {
        if ($this->normalize($po->shipment_method) !== null) {
            return;
        }

        $inferred = $this->inferFromPurchaseOrderId((int) $po->id);
        if ($inferred === null) {
            return;
        }

        $po->shipment_method = $inferred;
        $po->save();
    }

}

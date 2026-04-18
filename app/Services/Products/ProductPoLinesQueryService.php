<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Support\PurchaseOrders\PurchaseOrderAllocation;
use Illuminate\Support\Facades\DB;

final class ProductPoLinesQueryService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @return array<int, array{
     *   purchase_order_uuid:string,
     *   vendor:string,
     *   ordered_date:string|null,
     *   shipped_date:string|null,
     *   received_date:string|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   unit_cost:string|null,
     *   ship_per_unit:string,
     *   surcharge_per_unit:string,
     *   landed_unit_cost:string|null
     * }>
     */
    public function listForProduct(string $productUuid, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $product = $this->products->findByUuidOrFail($productUuid);
        $sku = (string) $product->sku;

        $rows = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.sku', '=', $sku)
            // Sort rule for PO Lines:
            // 1) Not arrived first (received_date is null), sorted by estimated_arrival_date desc.
            // 2) Arrived next, sorted by received_date desc.
            // 3) Stable fallback by creation/id.
            ->orderByRaw('CASE WHEN po.received_date IS NULL THEN 0 ELSE 1 END ASC')
            ->orderByRaw('CASE WHEN po.received_date IS NULL THEN po.estimated_arrival_date END DESC')
            ->orderByRaw('CASE WHEN po.received_date IS NOT NULL THEN po.received_date END DESC')
            ->orderByDesc('po.created_at')
            ->orderByDesc('po.id')
            ->orderByDesc('poi.id')
            ->limit($limit)
            ->get([
                'po.id as purchase_order_id',
                'po.uuid as purchase_order_uuid',
                'po.vendor as vendor',
                'po.ordered_date as ordered_date',
                'po.shipped_date as shipped_date',
                'po.received_date as received_date',
                'po.shipping_total as shipping_total',
                'po.surcharge_total as surcharge_total',
                'poi.qty_shipped as qty_shipped',
                'poi.qty_received as qty_received',
                'poi.unit_cost as unit_cost',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $poIds = $rows->pluck('purchase_order_id')->unique()->values()->all();
        $alloc = $this->allocationTotalsByPo($poIds);

        return $rows->map(function (object $r) use ($alloc): array {
            $poId = (int) $r->purchase_order_id;
            $sumReceived = $alloc[$poId]['sum_received'] ?? 0;
            $sumOrdered = $alloc[$poId]['sum_ordered'] ?? 0;
            $receivedEntriesCount = $alloc[$poId]['received_entries_count'] ?? 0;
            // Use received totals when qty_received has been entered (including 0); otherwise use ordered totals.
            $units = PurchaseOrderAllocation::unitsFromTotals($sumReceived, $sumOrdered, $receivedEntriesCount);

            $unitCents = $this->moneyToCentsOrNull($r->unit_cost);
            $shipPerUnit = $this->perUnitOrZero($r->shipping_total, $units);
            $surchargePerUnit = $this->perUnitOrZero($r->surcharge_total, $units);

            $unitCost = $unitCents !== null ? $this->centsToMoney($unitCents) : null;

            $landed = null;
            if ($unitCents !== null) {
                $shipCents = $this->moneyToCentsOrNull($shipPerUnit) ?? 0;
                $surchargeCents = $this->moneyToCentsOrNull($surchargePerUnit) ?? 0;
                $landed = $this->centsToMoney($unitCents + $shipCents + $surchargeCents);
            }

            return [
                'purchase_order_uuid' => (string) $r->purchase_order_uuid,
                'vendor' => (string) $r->vendor,
                'ordered_date' => $r->ordered_date !== null ? (string) $r->ordered_date : null,
                'shipped_date' => $r->shipped_date !== null ? (string) $r->shipped_date : null,
                'received_date' => $r->received_date !== null ? (string) $r->received_date : null,
                'qty_shipped' => $r->qty_shipped !== null ? (int) $r->qty_shipped : null,
                'qty_received' => $r->qty_received !== null ? (int) $r->qty_received : null,
                'unit_cost' => $unitCost,
                'ship_per_unit' => $shipPerUnit,
                'surcharge_per_unit' => $surchargePerUnit,
                'landed_unit_cost' => $landed,
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $purchaseOrderIds
     * @return array<int, array{sum_received:int, sum_ordered:int, received_entries_count:int}>
     */
    private function allocationTotalsByPo(array $purchaseOrderIds): array
    {
        if ($purchaseOrderIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', $purchaseOrderIds)
            ->groupBy('purchase_order_id')
            ->get([
                'purchase_order_id',
                DB::raw('SUM(COALESCE(qty_received, 0)) as sum_received'),
                DB::raw('SUM(COALESCE(qty_ordered, 0)) as sum_ordered'),
                DB::raw('SUM(CASE WHEN qty_received IS NULL THEN 0 ELSE 1 END) as received_entries_count'),
            ]);

        foreach ($rows as $r) {
            $out[(int) $r->purchase_order_id] = [
                'sum_received' => (int) $r->sum_received,
                'sum_ordered' => (int) $r->sum_ordered,
                'received_entries_count' => (int) $r->received_entries_count,
            ];
        }

        return $out;
    }

    private function perUnitOrZero(mixed $total, int $units): string
    {
        $cents = $this->moneyToCentsOrNull($total);
        if ($cents === null || $units <= 0) {
            return '0.00';
        }

        $perUnitCents = intdiv($cents + intdiv($units, 2), $units); // round half-up to nearest cent

        return $this->centsToMoney($perUnitCents);
    }

    private function centsToMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $dollars = intdiv($cents, 100);
        $rem = $cents % 100;

        return $sign.$dollars.'.'.str_pad((string) $rem, 2, '0', STR_PAD_LEFT);
    }

    private function moneyToCentsOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9\.\-]/', '', $s) ?? '';
        if ($clean === '' || $clean === '-' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        $neg = str_starts_with($clean, '-');
        if ($neg) {
            $clean = substr($clean, 1);
        }

        [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = $frac ?? '';

        $f = str_pad($frac, 3, '0'); // need 3rd digit for rounding
        $centsStr = substr($f, 0, 2);
        $third = (int) ($f[2] ?? '0');

        $cents = ((int) $whole) * 100 + (int) $centsStr;
        if ($third >= 5) {
            $cents++;
        }

        return $neg ? -$cents : $cents;
    }
}

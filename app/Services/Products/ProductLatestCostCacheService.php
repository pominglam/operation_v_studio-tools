<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductLatestCostCacheService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, string>  $skus
     * @return array{matched:int, updated:int}
     */
    public function recomputeForSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), static fn (string $s): bool => $s !== '')));
        if ($skus === []) {
            return ['matched' => 0, 'updated' => 0];
        }

        /** @var \Illuminate\Support\Collection<int, Product> $existing */
        $existing = $this->products->findBySkus($skus);
        $bySku = [];
        foreach ($existing as $p) {
            $bySku[$p->sku] = $p;
        }

        $matched = 0;
        $updated = 0;

        foreach ($skus as $sku) {
            if (! isset($bySku[$sku])) {
                continue;
            }
            $matched++;

            $calc = $this->latestCostForSku($sku);
            $product = $bySku[$sku];

            $product->latest_unit_cost = $calc['latest_unit_cost'];
            $product->latest_landed_unit_cost = $calc['latest_landed_unit_cost'];
            $this->products->save($product);
            $updated++;
        }

        return ['matched' => $matched, 'updated' => $updated];
    }

    /**
     * @return array{matched:int, updated:int}
     */
    public function recomputeAll(): array
    {
        $all = $this->products->listAll();
        $skus = $all->pluck('sku')->all();

        return $this->recomputeForSkus($skus);
    }

    /**
     * @return array{latest_unit_cost:string|null, latest_landed_unit_cost:string|null}
     */
    private function latestCostForSku(string $sku): array
    {
        $row = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.sku', '=', $sku)
            ->orderByDesc('po.created_at')
            ->orderByDesc('po.id')
            ->orderByDesc('poi.id')
            ->select([
                'poi.unit_cost as unit_cost',
                'poi.purchase_order_id as purchase_order_id',
                'po.shipping_total as shipping_total',
                'po.surcharge_total as surcharge_total',
                'po.received_date as received_date',
            ])
            ->first();

        if ($row === null) {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => null];
        }

        $unitCents = $this->moneyToCentsOrNull($row->unit_cost);
        if ($unitCents === null) {
            return ['latest_unit_cost' => null, 'latest_landed_unit_cost' => null];
        }

        $allocUnits = $this->allocationUnitsForPo((int) $row->purchase_order_id, $row->received_date !== null);
        $shipPerUnit = $this->perUnitOrZero($row->shipping_total, $allocUnits);
        $surchargePerUnit = $this->perUnitOrZero($row->surcharge_total, $allocUnits);

        $shipCents = $this->moneyToCentsOrNull($shipPerUnit) ?? 0;
        $surchargeCents = $this->moneyToCentsOrNull($surchargePerUnit) ?? 0;
        $landed = $this->money2FromCents($this->addCents($this->addCents($unitCents, $shipCents), $surchargeCents));

        return [
            'latest_unit_cost' => $this->money2FromCents($unitCents),
            'latest_landed_unit_cost' => $landed,
        ];
    }

    private function allocationUnitsForPo(int $purchaseOrderId, bool $useReceived): int
    {
        $col = $useReceived ? 'qty_received' : 'qty_ordered';
        $sum = DB::table('purchase_order_items')
            ->where('purchase_order_id', '=', $purchaseOrderId)
            ->selectRaw('SUM(COALESCE('.$col.', 0)) as total')
            ->value('total');

        return max(0, (int) $sum);
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

    private function addCents(int $a, int $b): int
    {
        return $a + $b;
    }

    private function money2FromCents(int $cents): string
    {
        return $this->centsToMoney($cents);
    }

    private function money2OrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '' || ! is_numeric(preg_replace('/[^0-9\.\-]/', '', $s) ?? '')) {
            return null;
        }

        $cents = $this->moneyToCentsOrNull($s);
        if ($cents === null) {
            return null;
        }

        return $this->money2FromCents($cents);
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

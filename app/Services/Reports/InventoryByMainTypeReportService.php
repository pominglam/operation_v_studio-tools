<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Products\ProductNotArrivedQtyService;
use Illuminate\Support\Facades\DB;

final class InventoryByMainTypeReportService
{
    public function __construct(
        private readonly ProductNotArrivedQtyService $notArrivedQty,
    ) {}

    /**
     * @return array{
     *     data_source: string,
     *     scope: string,
     *     currency: string,
     *     rows: list<array{
     *         type: string,
     *         type_label: string,
     *         main_type: string,
     *         catalog_skus: int,
     *         skus_on_hand: int,
     *         quantity_on_hand: int,
     *         not_arrived_skus: int,
     *         not_arrived: int,
     *         estimated_landed_value: string,
     *         estimated_not_landed_value: string,
     *         skus_missing_landed_cost: int,
     *         units_received: int,
     *         units_sold: int
     *     }>,
     *     totals: array{
     *         catalog_skus: int,
     *         skus_on_hand: int,
     *         quantity_on_hand: int,
     *         not_arrived_skus: int,
     *         not_arrived: int,
     *         estimated_landed_value: string,
     *         estimated_not_landed_value: string,
     *         skus_missing_landed_cost: int,
     *         units_received: int,
     *         units_sold: int
     *     },
     *     not_arrived_includes_draft_pos: bool
     * }
     */
    public function report(): array
    {
        $notArrivedExpr = $this->notArrivedQty->sqlExpressionForProductsGrid();
        $includesDraftPos = $this->notArrivedQty->productsGridIncludesDraftPurchaseOrders();
        $receivedQtyExpr = $this->totalReceivedQtyExpression();

        /** @var list<object{
         *     type: string,
         *     main_type: string,
         *     catalog_skus: int|string,
         *     skus_on_hand: int|string,
         *     quantity_on_hand: int|string,
         *     not_arrived_skus: int|string,
         *     not_arrived: int|string,
         *     estimated_landed_value: string|float|null,
         *     estimated_not_landed_value: string|float|null,
         *     skus_missing_landed_cost: int|string,
         *     units_received: int|string,
         *     units_sold: int|string
         * }> $rawRows */
        $rawRows = DB::table('products')
            ->whereNull('archived_at')
            ->selectRaw("COALESCE(NULLIF(TRIM(type), ''), '') as type")
            ->selectRaw("COALESCE(NULLIF(TRIM(main_type), ''), '') as main_type")
            ->selectRaw('COUNT(*) as catalog_skus')
            ->selectRaw('SUM(CASE WHEN available_qty > 0 THEN 1 ELSE 0 END) as skus_on_hand')
            ->selectRaw('SUM(CASE WHEN available_qty > 0 THEN available_qty ELSE 0 END) as quantity_on_hand')
            ->selectRaw("SUM(CASE WHEN ({$notArrivedExpr}) > 0 THEN 1 ELSE 0 END) as not_arrived_skus")
            ->selectRaw("SUM({$notArrivedExpr}) as not_arrived")
            ->selectRaw(
                'SUM(CASE WHEN available_qty > 0 AND latest_landed_unit_cost IS NOT NULL '
                .'THEN available_qty * latest_landed_unit_cost ELSE 0 END) as estimated_landed_value',
            )
            ->selectRaw(
                'SUM(CASE WHEN latest_landed_unit_cost IS NOT NULL '
                ."THEN ({$notArrivedExpr}) * latest_landed_unit_cost ELSE 0 END) as estimated_not_landed_value",
            )
            ->selectRaw(
                'SUM(CASE WHEN available_qty > 0 AND latest_landed_unit_cost IS NULL THEN 1 ELSE 0 END) '
                .'as skus_missing_landed_cost',
            )
            ->selectRaw("SUM({$receivedQtyExpr}) as units_received")
            ->selectRaw(
                "SUM(({$receivedQtyExpr}) - coalesce(products.available_qty, 0)) as units_sold",
            )
            ->groupBy('type', 'main_type')
            ->orderByDesc('quantity_on_hand')
            ->orderBy('type')
            ->orderBy('main_type')
            ->get()
            ->all();

        $rows = [];
        $totals = [
            'catalog_skus' => 0,
            'skus_on_hand' => 0,
            'quantity_on_hand' => 0,
            'not_arrived_skus' => 0,
            'not_arrived' => 0,
            'estimated_landed_value' => '0.00',
            'estimated_not_landed_value' => '0.00',
            'skus_missing_landed_cost' => 0,
            'units_received' => 0,
            'units_sold' => 0,
        ];

        $landedCentsTotal = 0;
        $notLandedCentsTotal = 0;

        foreach ($rawRows as $rawRow) {
            $type = (string) $rawRow->type;
            $mainType = (string) $rawRow->main_type;
            $catalogSkus = (int) $rawRow->catalog_skus;
            $skusOnHand = (int) $rawRow->skus_on_hand;
            $quantityOnHand = (int) $rawRow->quantity_on_hand;
            $notArrivedSkus = (int) $rawRow->not_arrived_skus;
            $notArrived = max(0, (int) $rawRow->not_arrived);
            $skusMissingLanded = (int) $rawRow->skus_missing_landed_cost;
            $unitsReceived = max(0, (int) $rawRow->units_received);
            $unitsSold = max(0, (int) $rawRow->units_sold);
            $landedCents = $this->moneyToCents((string) ($rawRow->estimated_landed_value ?? '0'));
            $notLandedCents = $this->moneyToCents((string) ($rawRow->estimated_not_landed_value ?? '0'));

            $rows[] = [
                'type' => $type,
                'type_label' => $this->labelForType($type),
                'main_type' => $mainType,
                'catalog_skus' => $catalogSkus,
                'skus_on_hand' => $skusOnHand,
                'quantity_on_hand' => $quantityOnHand,
                'not_arrived_skus' => $notArrivedSkus,
                'not_arrived' => $notArrived,
                'estimated_landed_value' => $this->money2FromCents($landedCents),
                'estimated_not_landed_value' => $this->money2FromCents($notLandedCents),
                'skus_missing_landed_cost' => $skusMissingLanded,
                'units_received' => $unitsReceived,
                'units_sold' => $unitsSold,
            ];

            $totals['catalog_skus'] += $catalogSkus;
            $totals['skus_on_hand'] += $skusOnHand;
            $totals['quantity_on_hand'] += $quantityOnHand;
            $totals['not_arrived_skus'] += $notArrivedSkus;
            $totals['not_arrived'] += $notArrived;
            $totals['skus_missing_landed_cost'] += $skusMissingLanded;
            $totals['units_received'] += $unitsReceived;
            $totals['units_sold'] += $unitsSold;
            $landedCentsTotal += $landedCents;
            $notLandedCentsTotal += $notLandedCents;
        }

        $totals['estimated_landed_value'] = $this->money2FromCents($landedCentsTotal);
        $totals['estimated_not_landed_value'] = $this->money2FromCents($notLandedCentsTotal);

        return [
            'data_source' => 'products',
            'scope' => 'active_products_on_hand_available_qty',
            'currency' => 'CAD',
            'rows' => $rows,
            'totals' => $totals,
            'not_arrived_includes_draft_pos' => $includesDraftPos,
        ];
    }

    private function labelForType(string $type): string
    {
        if ($type === '') {
            return '(unset)';
        }

        return $type;
    }

    /**
     * Lifetime received units per SKU — same SQL as Products grid total_ordered.
     */
    private function totalReceivedQtyExpression(): string
    {
        return '(
            select coalesce(sum(coalesce(poi.qty_received, 0)), 0)
            from purchase_order_items poi
            inner join purchase_orders po on po.id = poi.purchase_order_id
            where poi.product_id = products.id
              and po.received_date is not null
        )';
    }

    private function moneyToCents(string $value): int
    {
        $clean = preg_replace('/[^0-9\.\-]/', '', trim($value)) ?? '';
        if ($clean === '' || $clean === '-' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return 0;
        }

        $neg = str_starts_with($clean, '-');
        if ($neg) {
            $clean = substr($clean, 1);
        }

        [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = str_pad($frac, 3, '0', STR_PAD_RIGHT);
        $cents = ((int) $whole) * 100 + (int) substr($frac, 0, 2);
        if ((int) ($frac[2] ?? '0') >= 5) {
            $cents++;
        }

        return $neg ? -$cents : $cents;
    }

    private function money2FromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $dollars = intdiv($cents, 100);
        $rem = $cents % 100;

        return $sign.$dollars.'.'.str_pad((string) $rem, 2, '0', STR_PAD_LEFT);
    }
}

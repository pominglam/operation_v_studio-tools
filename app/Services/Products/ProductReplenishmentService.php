<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductReplenishmentService
{
    /**
     * @return Collection<int, array{
     *   sku:string,
     *   product_name:string,
     *   barcode:?string,
     *   available_qty:int,
     *   maintain_qty:int,
     *   inbound_open_po_qty:int,
     *   suggested_order_qty:int
     * }>
     */
    public function previewRows(): Collection
    {
        $inboundExpr = $this->inboundOpenPoQtyExpression();
        $suggestedExpr = $this->suggestedOrderQtyExpression($inboundExpr);

        /** @var Collection<int, object{sku:string,description:string,barcode:?string,available_qty:int,maintain_qty:int,inbound_open_po_qty:int,suggested_order_qty:int}> $rows */
        $rows = Product::query()
            ->whereNull('archived_at')
            ->whereNotNull('maintain_qty')
            ->where('maintain_qty', '>', 0)
            ->whereRaw("{$suggestedExpr} > 0")
            ->select([
                'sku',
                'description',
                'barcode',
                DB::raw('coalesce(available_qty, 0) as available_qty'),
                DB::raw('coalesce(maintain_qty, 0) as maintain_qty'),
                DB::raw("{$inboundExpr} as inbound_open_po_qty"),
                DB::raw("{$suggestedExpr} as suggested_order_qty"),
            ])
            ->orderBy('sku', 'asc')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'sku' => (string) $row->sku,
            'product_name' => (string) $row->description,
            'barcode' => is_string($row->barcode) ? $row->barcode : null,
            'available_qty' => max(0, (int) $row->available_qty),
            'maintain_qty' => max(0, (int) $row->maintain_qty),
            'inbound_open_po_qty' => max(0, (int) $row->inbound_open_po_qty),
            'suggested_order_qty' => max(0, (int) $row->suggested_order_qty),
        ]);
    }

    private function inboundOpenPoQtyExpression(): string
    {
        return '(
            select coalesce(sum(
                case when coalesce(poi.qty_ordered, 0) > 0 then coalesce(poi.qty_ordered, 0) else 0 end
            ), 0)
            from purchase_order_items poi
            inner join purchase_orders po on po.id = poi.purchase_order_id
            where poi.product_id = products.id
              and po.received_date is null
        )';
    }

    private function suggestedOrderQtyExpression(string $inboundExpr): string
    {
        $delta = "coalesce(products.maintain_qty, 0) - coalesce(products.available_qty, 0) - ({$inboundExpr})";

        return "case when ({$delta}) > 0 then ({$delta}) else 0 end";
    }
}


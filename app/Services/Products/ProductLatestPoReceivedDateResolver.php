<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductLatestPoReceivedDateResolver
{
    public function forProduct(Product $product): ?string
    {
        $cached = $product->getAttribute('latest_po_received_date');
        if ($cached !== null && $cached !== '') {
            return substr((string) $cached, 0, 10);
        }

        $date = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.product_id', '=', $product->id)
            ->whereNotNull('po.received_date')
            ->max('po.received_date');

        if (! is_string($date) || $date === '') {
            return null;
        }

        return substr($date, 0, 10);
    }
}

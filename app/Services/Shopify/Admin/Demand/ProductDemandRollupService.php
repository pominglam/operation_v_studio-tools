<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Demand;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductDemandDailyRollup;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderLineItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ProductDemandRollupService
{
    public const int DEMAND_WINDOW_DAYS = 28;

    public const int SUMMARY_4W_DAYS = 28;

    public const int SUMMARY_12W_DAYS = 84;

    public const int DETAIL_WINDOW_DAYS = 365;

    public function adjustShopifySold(?int $productId, ?CarbonInterface $soldOn, int $delta): void
    {
        if ($productId === null || $soldOn === null || $delta === 0) {
            return;
        }

        $soldOnDay = $soldOn->format('Y-m-d');

        /** @var ProductDemandDailyRollup|null $rollup */
        $rollup = ProductDemandDailyRollup::query()
            ->where('product_id', $productId)
            ->whereDate('sold_on', $soldOnDay)
            ->first();

        if ($rollup === null) {
            $rollup = ProductDemandDailyRollup::query()->create([
                'product_id' => $productId,
                'sold_on' => $soldOnDay,
                'shopify_sold' => 0,
                'assumed_sold' => 0,
            ]);
        }
        $rollup->shopify_sold = max(0, (int) $rollup->shopify_sold + $delta);
        $rollup->save();
    }

    public function soldLast4WeeksForProduct(int $productId): int
    {
        $since = now()->subDays(self::DEMAND_WINDOW_DAYS)->toDateString();

        return (int) ProductDemandDailyRollup::query()
            ->where('product_id', $productId)
            ->where('sold_on', '>=', $since)
            ->selectRaw('coalesce(sum(shopify_sold + assumed_sold), 0) as total')
            ->value('total');
    }

    public function rebuildAll(): array
    {
        ProductDemandDailyRollup::query()->delete();

        $shopifyRows = ShopifyOrderLineItem::query()
            ->demandEligible()
            ->whereNotNull('product_id')
            ->whereNotNull('sold_on')
            ->selectRaw('product_id, sold_on, sum(quantity) as qty')
            ->groupBy('product_id', 'sold_on')
            ->get();

        foreach ($shopifyRows as $row) {
            ProductDemandDailyRollup::query()->create([
                'product_id' => (int) $row->product_id,
                'sold_on' => $row->sold_on,
                'shopify_sold' => (int) $row->qty,
                'assumed_sold' => 0,
            ]);
        }

        $movementRows = DB::table('inventory_movements')
            ->whereIn('kind', ['deduct', 'underflow'])
            ->where('qty_delta', '<', 0)
            ->selectRaw('product_id, date(occurred_at) as sold_on, sum(abs(qty_delta)) as qty')
            ->groupBy('product_id', DB::raw('date(occurred_at)'))
            ->get();

        $assumedCount = 0;
        foreach ($movementRows as $row) {
            /** @var ProductDemandDailyRollup $rollup */
            $rollup = ProductDemandDailyRollup::query()->firstOrCreate(
                [
                    'product_id' => (int) $row->product_id,
                    'sold_on' => (string) $row->sold_on,
                ],
                ['shopify_sold' => 0, 'assumed_sold' => 0],
            );
            $rollup->assumed_sold = (int) $row->qty;
            $rollup->save();
            $assumedCount++;
        }

        return [
            'shopify_day_rows' => $shopifyRows->count(),
            'assumed_day_rows' => $assumedCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailForProduct(
        Product $product,
        int $linesPage = 1,
        int $linesPerPage = 10,
    ): array {
        $windowStart = now()->subDays(self::DETAIL_WINDOW_DAYS)->startOfWeek(Carbon::MONDAY);
        $windowEnd = now()->startOfWeek(Carbon::MONDAY);
        $detailSince = $windowStart->toDateString();
        $since4w = now()->subDays(self::SUMMARY_4W_DAYS)->toDateString();
        $since12w = now()->subDays(self::SUMMARY_12W_DAYS)->toDateString();

        $rollups = ProductDemandDailyRollup::query()
            ->where('product_id', $product->id)
            ->where('sold_on', '>=', $detailSince)
            ->orderByDesc('sold_on')
            ->get();

        $shopify4w = 0;
        $shopify12w = 0;
        $assumed4w = 0;
        $assumed12w = 0;

        foreach ($rollups as $rollup) {
            $soldOn = $rollup->sold_on?->toDateString() ?? '';
            if ($soldOn >= $since12w) {
                $shopify12w += (int) $rollup->shopify_sold;
                $assumed12w += (int) $rollup->assumed_sold;
            }
            if ($soldOn >= $since4w) {
                $shopify4w += (int) $rollup->shopify_sold;
                $assumed4w += (int) $rollup->assumed_sold;
            }
        }

        $sold4w = $shopify4w + $assumed4w;

        $linesPaginator = ShopifyOrderLineItem::query()
            ->demandEligible()
            ->where('product_id', $product->id)
            ->where('sold_on', '>=', $detailSince)
            ->orderByDesc('sold_on')
            ->orderByDesc('id')
            ->paginate(
                $linesPerPage,
                ['order_gid', 'quantity', 'sold_on'],
                'lines_page',
                $linesPage,
            );

        /** @var array<string, string|null> $orderNames */
        $orderNames = ShopifyOrder::query()
            ->whereIn('gid', collect($linesPaginator->items())->pluck('order_gid')->unique()->filter()->values())
            ->pluck('name', 'gid')
            ->all();

        $recentMovements = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->whereIn('kind', ['deduct', 'underflow'])
            ->where('qty_delta', '<', 0)
            ->where('occurred_at', '>=', now()->subDays(self::DETAIL_WINDOW_DAYS))
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['kind', 'qty_delta', 'occurred_at', 'reference_type']);

        return [
            'product_id' => $product->uuid,
            'sku' => $product->sku,
            'sold_4w' => $sold4w,
            'window_days' => self::DEMAND_WINDOW_DAYS,
            'detail_window_days' => self::DETAIL_WINDOW_DAYS,
            'shopify_sold_4w' => $shopify4w,
            'shopify_sold_12w' => $shopify12w,
            'assumed_sold_4w' => $assumed4w,
            'assumed_sold_12w' => $assumed12w,
            'formula' => 'shopify_4w + assumed_4w = sold_4w',
            'weekly_rollups' => $this->buildWeeklyRollups($rollups, $windowStart, $windowEnd),
            'recent_shopify_lines' => collect($linesPaginator->items())->map(function (ShopifyOrderLineItem $line) use ($orderNames): array {
                $orderGid = (string) $line->order_gid;

                return [
                    'order_gid' => $orderGid,
                    'order_name' => $orderNames[$orderGid] ?? null,
                    'order_admin_url' => $this->shopifyOrderAdminUrl($orderGid),
                    'quantity' => (int) $line->quantity,
                    'sold_on' => optional($line->sold_on)->toDateString(),
                ];
            })->values()->all(),
            'recent_shopify_lines_meta' => [
                'current_page' => $linesPaginator->currentPage(),
                'last_page' => $linesPaginator->lastPage(),
                'per_page' => $linesPaginator->perPage(),
                'total' => $linesPaginator->total(),
            ],
            'recent_assumed_movements' => $recentMovements->map(static fn (InventoryMovement $m): array => [
                'kind' => $m->kind,
                'quantity' => abs((int) $m->qty_delta),
                'occurred_at' => optional($m->occurred_at)->toISOString(),
                'reference_type' => $m->reference_type,
            ])->values()->all(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductDemandDailyRollup>  $rollups
     * @return list<array{week_start: string, shopify_sold: int, assumed_sold: int, total: int}>
     */
    private function buildWeeklyRollups($rollups, Carbon $windowStart, Carbon $windowEnd): array
    {
        /** @var array<string, array{shopify_sold: int, assumed_sold: int}> $byWeek */
        $byWeek = [];

        foreach ($rollups as $rollup) {
            if ($rollup->sold_on === null) {
                continue;
            }

            $weekStart = Carbon::parse($rollup->sold_on)->startOfWeek(Carbon::MONDAY)->toDateString();
            if (! isset($byWeek[$weekStart])) {
                $byWeek[$weekStart] = ['shopify_sold' => 0, 'assumed_sold' => 0];
            }

            $byWeek[$weekStart]['shopify_sold'] += (int) $rollup->shopify_sold;
            $byWeek[$weekStart]['assumed_sold'] += (int) $rollup->assumed_sold;
        }

        $rows = [];
        $cursor = $windowStart->copy();

        while ($cursor->lte($windowEnd)) {
            $weekStart = $cursor->toDateString();
            $shopifySold = (int) ($byWeek[$weekStart]['shopify_sold'] ?? 0);
            $assumedSold = (int) ($byWeek[$weekStart]['assumed_sold'] ?? 0);
            $rows[] = [
                'week_start' => $weekStart,
                'shopify_sold' => $shopifySold,
                'assumed_sold' => $assumedSold,
                'total' => $shopifySold + $assumedSold,
            ];
            $cursor->addWeek();
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['week_start'], $a['week_start']));

        return $rows;
    }

    private function shopifyOrderAdminUrl(string $orderGid): ?string
    {
        if (! preg_match('#/Order/(\d+)$#', $orderGid, $matches)) {
            return null;
        }

        /** @var string|null $domain */
        $domain = config('shopify.store_domain');
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        $host = strtolower(trim(str_replace(['https://', 'http://'], '', $domain)));

        return sprintf('https://%s/admin/orders/%s', $host, $matches[1]);
    }
}

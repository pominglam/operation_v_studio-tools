<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Jobs\CustomOrders\RefreshCustomAsiaOrderCompetitorPricesJob;
use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderCompetitorPriceSites;
use App\Support\CustomOrders\CustomAsiaOrderCompetitorPricesRefreshStatus;
use Illuminate\Support\Carbon;
use Throwable;

final class CustomAsiaOrderCompetitorPricesRefreshService
{
    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
        private readonly CustomAsiaOrderCompetitorPriceLookupService $lookup,
    ) {}

    public function queueRefresh(string $uuid, ?string $scope = null): CustomAsiaOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $productName = trim((string) ($order->product_name ?? ''));
        if ($productName === '') {
            throw new \InvalidArgumentException('Set a product name before searching competitor prices.');
        }

        if (CustomAsiaOrderCompetitorPricesRefreshStatus::isInProgress($order->competitor_prices_refresh_status)) {
            return $order;
        }

        $normalizedScope = CustomAsiaOrderCompetitorPriceSites::normalizeScope($scope);
        $siteKeys = CustomAsiaOrderCompetitorPriceSites::siteKeysForScope($normalizedScope);

        $order = $this->orders->update($order, [
            'competitor_prices_product_name' => $productName,
            'competitor_prices_refresh_status' => CustomAsiaOrderCompetitorPricesRefreshStatus::QUEUED,
            'competitor_prices_refresh_scope' => $normalizedScope,
            'competitor_prices_refresh_error' => null,
            'competitor_price_quotes_json' => CustomAsiaOrderCompetitorPriceSites::pendingQuotesForSiteKeys($siteKeys),
            'competitor_prices_fetched_at' => null,
        ]);

        RefreshCustomAsiaOrderCompetitorPricesJob::dispatch($order->uuid, $normalizedScope);

        return $order->fresh() ?? $order;
    }

    public function executeRefresh(string $uuid, string $scope): void
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $productName = trim((string) ($order->competitor_prices_product_name ?? $order->product_name ?? ''));
        if ($productName === '') {
            $this->markFailed($order, 'Set a product name before searching competitor prices.');

            return;
        }

        $normalizedScope = CustomAsiaOrderCompetitorPriceSites::normalizeScope($scope);
        $siteKeys = CustomAsiaOrderCompetitorPriceSites::siteKeysForScope($normalizedScope);

        $this->orders->update($order, [
            'competitor_prices_refresh_status' => CustomAsiaOrderCompetitorPricesRefreshStatus::RUNNING,
            'competitor_prices_refresh_error' => null,
        ]);

        try {
            $quotes = $this->lookup->lookupParallelByProductName($productName, $siteKeys);

            $this->orders->update($order, [
                'competitor_prices_product_name' => $productName,
                'competitor_price_quotes_json' => $quotes,
                'competitor_prices_fetched_at' => Carbon::now('America/Toronto'),
                'competitor_prices_refresh_status' => CustomAsiaOrderCompetitorPricesRefreshStatus::COMPLETED,
                'competitor_prices_refresh_scope' => $normalizedScope,
                'competitor_prices_refresh_error' => null,
            ]);
        } catch (Throwable $e) {
            $this->markFailed($order, $e->getMessage());
        }
    }

    public function resumeIfStuck(CustomAsiaOrder $order): CustomAsiaOrder
    {
        if (! app()->environment('local')) {
            return $order;
        }

        if (! (bool) config('price_research.local_inline_queue_fallback', true)) {
            return $order;
        }

        if ($order->competitor_prices_refresh_status !== CustomAsiaOrderCompetitorPricesRefreshStatus::QUEUED) {
            return $order;
        }

        if (config('queue.default') === 'sync') {
            return $order;
        }

        $stuckSeconds = max(0, (int) config('price_research.local_queue_stuck_seconds', 3));
        if ($stuckSeconds > 0 && $order->updated_at !== null && $order->updated_at->diffInSeconds(now()) < $stuckSeconds) {
            return $order;
        }

        $scope = CustomAsiaOrderCompetitorPriceSites::normalizeScope($order->competitor_prices_refresh_scope);
        $this->executeRefresh($order->uuid, $scope);

        return $this->orders->findByUuidOrFail($order->uuid);
    }

    private function markFailed(CustomAsiaOrder $order, string $message): void
    {
        $this->orders->update($order, [
            'competitor_prices_refresh_status' => CustomAsiaOrderCompetitorPricesRefreshStatus::FAILED,
            'competitor_prices_refresh_error' => $message,
        ]);
    }
}

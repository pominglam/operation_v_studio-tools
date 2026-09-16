<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\DAL\Shopify\ShopifySyncStateRepository;
use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\ShopifySettingsService;
use App\Support\Shopify\Admin\Orders\ShopifyOrderIndexFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ShopifyOrderQueryService
{
    public function __construct(
        private readonly ShopifyOrderChannelPresenter $channels,
        private readonly ShopifySyncStateRepository $syncState,
        private readonly ShopifyOrderDemandEligibility $eligibility,
    ) {}

    /**
     * @return array{paginator: LengthAwarePaginator<int, ShopifyOrder>, summary: array<string, mixed>}
     */
    public function paginate(ShopifyOrderIndexFilters $filters): array
    {
        $query = $this->filteredQuery($filters)
            ->with(['storeEvent:id,uuid,name'])
            ->withCount('lineItems')
            ->withExists('storePreorderLines as has_store_preorder');
        $this->applySort($query, $filters);

        /** @var LengthAwarePaginator<int, ShopifyOrder> $paginator */
        $paginator = $query->paginate(perPage: $filters->perPage);

        return [
            'paginator' => $paginator,
            'summary' => $this->summary($filters),
        ];
    }

    public function findById(int $id): ?ShopifyOrder
    {
        /** @var ShopifyOrder|null $order */
        $order = ShopifyOrder::query()
            ->with(['lineItems.product:id,sku,description', 'storeEvent:id,uuid,name'])
            ->withCount('lineItems')
            ->withExists('storePreorderLines as has_store_preorder')
            ->find($id);

        return $order;
    }

    /**
     * @return Builder<ShopifyOrder>
     */
    private function filteredQuery(ShopifyOrderIndexFilters $filters): Builder
    {
        $query = ShopifyOrder::query();
        $this->applyDateRange($query, $filters->fromDate, $filters->untilDate);
        $this->applyStatus($query, $filters->status);
        $this->applyPreorder($query, $filters->preorder);
        $this->applySearch($query, $filters->search);
        $this->applyChannel($query, $filters->channel);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ShopifyOrderIndexFilters $filters): array
    {
        $base = $this->filteredQuery($filters);
        $state = $this->syncState->findByKey(ShopifySettingsService::SYNC_KEY_ORDERS);

        return [
            'filtered_count' => (int) (clone $base)->count(),
            'filtered_subtotal' => number_format((float) (clone $base)->sum('subtotal_shop_amount'), 2, '.', ''),
            'last_synced_at' => $state?->last_success_at?->toISOString(),
            'timezone' => (string) config('shopify.staff_order_report.timezone', 'America/Toronto'),
            'revenue_currency' => 'CAD',
            'channel_options' => $this->channels->options(),
            'from_date' => $filters->fromDate,
            'until_date' => $filters->untilDate,
        ];
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applyDateRange(Builder $query, string $fromDate, string $untilDate): void
    {
        $timezone = (string) config('shopify.staff_order_report.timezone', 'America/Toronto');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $fromDate, $timezone);
        $end = CarbonImmutable::createFromFormat('Y-m-d', $untilDate, $timezone);
        if ($start === false || $end === false) {
            return;
        }

        $query
            ->where('ordered_at_shop_tz', '>=', $start->startOfDay())
            ->where('ordered_at_shop_tz', '<', $end->startOfDay()->addDay());
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applyStatus(Builder $query, string $status): void
    {
        if ($status === 'eligible') {
            $this->eligibility->scopeDemandEligibleOrders($query);

            return;
        }

        if ($status === 'cancelled') {
            $query->where(function (Builder $inner): void {
                $inner->whereNotNull('cancelled_at')
                    ->orWhere('display_financial_status', 'VOIDED');
            });
        }
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applyPreorder(Builder $query, string $preorder): void
    {
        if ($preorder !== 'only') {
            return;
        }

        $query->whereHas('storePreorderLines');
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $term = is_string($search) ? trim($search) : '';
        if ($term === '') {
            return;
        }

        $stripped = ltrim($term, '#');
        $query->where(function (Builder $inner) use ($term, $stripped): void {
            $inner->where('name', 'like', '%'.$term.'%');
            if ($stripped !== '' && $stripped !== $term) {
                $inner->orWhere('name', 'like', '%'.$stripped.'%');
            }
        });
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applyChannel(Builder $query, ?string $channel): void
    {
        $key = is_string($channel) ? trim($channel) : '';
        if ($key === '') {
            return;
        }

        if ($key === 'unattributed') {
            $query->where(function (Builder $inner): void {
                $inner->whereNull('source_name')->orWhere('source_name', '');
            });

            return;
        }

        if ($key === 'quick_sale') {
            $query->where('source_name', 'quick_sale');
            $this->excludeCashAndNtSales($query);

            return;
        }

        if ($key === 'online_store') {
            $query->where('source_name', 'web');
            $this->excludeCashAndNtSales($query);

            return;
        }

        if ($key === 'special_order') {
            $this->applySpecialOrderChannel($query);

            return;
        }

        if ($key === 'cash_sale') {
            $this->applyCashSaleChannel($query);

            return;
        }

        if ($key === 'nt_sales') {
            $this->applyNtSalesChannel($query);

            return;
        }

        $this->applyPosOrShopChannel($query, $key);
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applySpecialOrderChannel(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->where('source_name', 'shopify_draft_order')
                ->orWhereRaw(
                    "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) LIKE ?",
                    ['%special-order%'],
                )
                ->orWhereRaw(
                    "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) LIKE ?",
                    ['%special-deposit%'],
                )
                ->orWhereRaw(
                    "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) LIKE ?",
                    ['%special-balance%'],
                )
                ->orWhereRaw(
                    "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) LIKE ?",
                    ['%special_order%'],
                );
        });
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applyCashSaleChannel(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereRaw(
                'LOWER(CAST(COALESCE(payment_gateway_names, JSON_ARRAY()) AS CHAR)) LIKE ?',
                ['%cash%'],
            )->orWhereRaw(
                "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) REGEXP ?",
                ['"cash"'],
            );
        });
        $query->whereRaw(
            "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) NOT REGEXP ?",
            ['"nt"|nt-sale|nt_sales'],
        );
        $this->excludeSpecialOrderSignals($query);
    }

    private function applyNtSalesChannel(Builder $query): void
    {
        $query->whereRaw(
            "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) REGEXP ?",
            ['"nt"|nt-sale|nt_sales'],
        );
        $this->excludeSpecialOrderSignals($query);
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applyPosOrShopChannel(Builder $query, string $key): void
    {
        if ($key === 'shop') {
            $query->where(function (Builder $inner): void {
                $inner->whereRaw('LOWER(channel_name) = ?', ['shop'])
                    ->orWhere(function (Builder $other): void {
                        $other->whereNotNull('source_name')
                            ->where('source_name', '!=', '')
                            ->whereNotIn('source_name', ['web', 'pos', 'quick_sale', 'shopify_draft_order']);
                    });
            });
            $this->excludeCashAndNtSales($query);

            return;
        }

        $staffIds = array_keys($this->channels->staffByUserId());
        if ($key === 'pos_other') {
            $query->where('source_name', 'pos');
            if ($staffIds !== []) {
                $query->where(function (Builder $inner) use ($staffIds): void {
                    $inner->whereNull('pos_user_id')->orWhereNotIn('pos_user_id', $staffIds);
                });
            }
            $this->excludeCashAndNtSales($query);

            return;
        }

        foreach ($this->channels->staffByUserId() as $userId => $staff) {
            if ($staff['key'] === $key) {
                $query->where('source_name', 'pos')->where('pos_user_id', (int) $userId);
                $this->excludeCashAndNtSales($query);

                return;
            }
        }
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function excludeCashAndNtSales(Builder $query): void
    {
        $query->whereRaw(
            'LOWER(CAST(COALESCE(payment_gateway_names, JSON_ARRAY()) AS CHAR)) NOT LIKE ?',
            ['%cash%'],
        );
        $query->whereRaw(
            "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) NOT REGEXP ?",
            ['"nt"|nt-sale|nt_sales|"cash"'],
        );
    }

    private function excludeSpecialOrderSignals(Builder $query): void
    {
        $query->where('source_name', '!=', 'shopify_draft_order');
        $query->whereRaw(
            "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) NOT LIKE ?",
            ['%special-order%'],
        );
        $query->whereRaw(
            "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) NOT LIKE ?",
            ['%special-deposit%'],
        );
        $query->whereRaw(
            "LOWER(CAST(JSON_EXTRACT(COALESCE(payload_json, JSON_OBJECT()), '$.tags') AS CHAR)) NOT LIKE ?",
            ['%special-balance%'],
        );
    }

    /**
     * @param  Builder<ShopifyOrder>  $query
     */
    private function applySort(Builder $query, ShopifyOrderIndexFilters $filters): void
    {
        $dir = $filters->sortDir === 'asc' ? 'asc' : 'desc';
        match ($filters->sortBy) {
            'name' => $query->orderBy('name', $dir),
            'contact' => $query->orderBy('customer_email', $dir)->orderBy('customer_phone', $dir),
            'channel' => $query->orderBy('source_name', $dir)->orderBy('channel_name', $dir),
            'financial_status' => $query->orderBy('display_financial_status', $dir),
            'fulfillment_status' => $query->orderBy('display_fulfillment_status', $dir),
            'subtotal' => $query->orderBy('subtotal_shop_amount', $dir),
            'line_count' => $query->orderBy('line_items_count', $dir),
            default => $query->orderBy('ordered_at_shop_tz', $dir),
        };
        $query->orderBy('id', $dir);
    }
}

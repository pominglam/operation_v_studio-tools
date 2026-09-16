<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Customers;

use App\Models\Shopify\ShopifyCustomer;
use App\Services\Shopify\Admin\Sync\ShopifyCustomerMirrorFreshnessService;
use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use Illuminate\Support\Collection;

final class ShopifyCustomerSuggestService
{
    public function __construct(
        private readonly ShopifyCustomerMirrorFreshnessService $freshness,
        private readonly ShopifyErpSyncCoordinator $syncCoordinator,
    ) {}

    /**
     * @return list<array{gid: string, display_name: string|null, email: string|null, legacy_numeric_id: string|null}>
     */
    public function suggest(string $query, int $limit = 8): array
    {
        $this->ensureMirrorFresh();

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $terms = preg_split('/\s+/', mb_strtolower($query)) ?: [];
        $terms = array_values(array_filter($terms, static fn (string $term): bool => $term !== ''));

        /** @var Collection<int, ShopifyCustomer> $rows */
        $rows = ShopifyCustomer::query()
            ->where(static function ($builder) use ($query, $terms): void {
                $builder->where('email', 'like', '%'.$query.'%')
                    ->orWhere('display_name', 'like', '%'.$query.'%');

                foreach ($terms as $term) {
                    $builder->orWhere('email', 'like', '%'.$term.'%')
                        ->orWhere('display_name', 'like', '%'.$term.'%');
                }
            })
            ->orderByDesc('customer_created_at')
            ->limit($limit)
            ->get();

        return $rows->map(static fn (ShopifyCustomer $customer): array => [
            'gid' => (string) $customer->gid,
            'display_name' => $customer->display_name,
            'email' => $customer->email,
            'legacy_numeric_id' => $customer->legacy_numeric_id,
        ])->all();
    }

    private function ensureMirrorFresh(): void
    {
        if ($this->freshness->isFresh()) {
            return;
        }

        $log = $this->syncCoordinator->sync('customers');
        if ($log->status !== 'completed') {
            throw new \RuntimeException('Shopify customer sync failed. Try again from Maintenance.');
        }
    }
}

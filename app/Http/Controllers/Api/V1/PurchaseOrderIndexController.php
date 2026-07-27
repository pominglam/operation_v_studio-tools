<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\PurchaseOrderQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PurchaseOrderIndexController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrders,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->query('per_page') ?? 50);
        $perPage = max(1, min($perPage, 200));

        /** @var string $sortBy */
        $sortBy = (string) ($request->query('sort_by') ?? 'ordered');
        $sortBy = strtolower(trim($sortBy));
        if (! in_array($sortBy, ['created', 'ordered', 'received', 'filter'], true)) {
            $sortBy = 'ordered';
        }

        /** @var string $sortDir */
        $sortDir = (string) ($request->query('sort_dir') ?? 'desc');
        $sortDir = strtolower(trim($sortDir)) === 'asc' ? 'asc' : 'desc';

        /** @var array<int, string> $vendorFilters */
        $vendorFilters = [];
        $rawVendors = $request->query('vendors');
        if (is_array($rawVendors)) {
            foreach ($rawVendors as $v) {
                $t = trim((string) $v);
                if ($t !== '') {
                    $vendorFilters[] = $t;
                }
            }
        }
        $vendorFilters = array_values(array_unique($vendorFilters));
        $vendorFilters = array_slice($vendorFilters, 0, 50);

        /** @var array<int, string> $statusFilters */
        $statusFilters = [];
        $rawStatuses = $request->query('statuses');
        if (is_array($rawStatuses)) {
            foreach ($rawStatuses as $status) {
                $next = trim(strtolower((string) $status));
                if (in_array($next, ['draft', 'ordered', 'shipped', 'received', 'on_shelves'], true)) {
                    $statusFilters[] = $next;
                }
            }
        }
        $statusFilters = array_values(array_unique($statusFilters));
        $statusFilters = array_slice($statusFilters, 0, 20);

        return PurchaseOrderResource::collection(
            $this->purchaseOrders->paginate($perPage, $sortDir, $sortBy, $vendorFilters, $statusFilters),
        );
    }
}

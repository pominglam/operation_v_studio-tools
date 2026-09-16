<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePreorderIndexRequest;
use App\Http\Resources\Api\V1\StorePreorderResource;
use App\Services\StorePreorders\StorePreorderOrderStatsService;
use App\Services\StorePreorders\StorePreorderQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StorePreorderIndexController extends Controller
{
    public function __construct(
        private readonly StorePreorderQueryService $offers,
        private readonly StorePreorderOrderStatsService $orderStats,
    ) {}

    public function __invoke(StorePreorderIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $perPage = max(1, min((int) ($validated['per_page'] ?? 50), 200));

        $status = isset($validated['status']) ? (string) $validated['status'] : null;
        $paginator = $this->offers->paginate(
            $perPage,
            isset($validated['sort_by']) ? (string) $validated['sort_by'] : null,
            isset($validated['sort_dir']) ? (string) $validated['sort_dir'] : null,
            $status,
            isset($validated['search']) ? (string) $validated['search'] : null,
            isset($validated['closes_on']) ? (string) $validated['closes_on'] : null,
            $request->boolean('has_units'),
        );
        $stats = $this->orderStats->totals();

        return StorePreorderResource::collection($paginator)->additional([
            'preorder_orders' => [
                'order_count' => $stats->orderCount,
                'unit_qty' => $stats->unitQty,
            ],
            'closing_dates' => $this->offers->closingDates($status),
        ]);
    }
}

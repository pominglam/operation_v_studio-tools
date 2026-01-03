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

        /** @var string $sortDir */
        $sortDir = (string) ($request->query('sort_dir') ?? 'desc');
        $sortDir = strtolower(trim($sortDir)) === 'asc' ? 'asc' : 'desc';

        return PurchaseOrderResource::collection(
            $this->purchaseOrders->paginate($perPage, $sortDir),
        );
    }
}



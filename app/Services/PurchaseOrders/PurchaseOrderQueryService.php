<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PurchaseOrderQueryService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    public function paginate(int $perPage, string $sortDir = 'desc'): LengthAwarePaginator
    {
        return $this->purchaseOrders->paginate($perPage, $sortDir);
    }

    public function findByUuidOrFail(string $uuid): PurchaseOrder
    {
        return $this->purchaseOrders->findByUuidOrFail($uuid);
    }
}



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

    /**
     * @param  array<int, string>  $vendors
     */
    public function paginate(int $perPage, string $sortDir = 'desc', string $sortBy = 'created', array $vendors = []): LengthAwarePaginator
    {
        return $this->purchaseOrders->paginate($perPage, $sortDir, $sortBy, $vendors);
    }

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array
    {
        return $this->purchaseOrders->distinctVendors();
    }

    public function findByUuidOrFail(string $uuid): PurchaseOrder
    {
        return $this->purchaseOrders->findByUuidOrFail($uuid);
    }
}

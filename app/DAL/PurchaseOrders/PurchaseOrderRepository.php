<?php

declare(strict_types=1);

namespace App\DAL\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PurchaseOrderRepository
{
    public function create(PurchaseOrder $po): PurchaseOrder;

    public function save(PurchaseOrder $po): PurchaseOrder;

    public function createItem(PurchaseOrderItem $item): PurchaseOrderItem;

    public function findItemByIdOrFail(int $id): PurchaseOrderItem;

    /**
     * @return Collection<int, PurchaseOrderItem>
     */
    public function itemsForPurchaseOrderId(int $purchaseOrderId): Collection;

    public function saveItem(PurchaseOrderItem $item): PurchaseOrderItem;

    /**
     * @return LengthAwarePaginator<PurchaseOrder>
     */
    public function paginate(int $perPage, string $sortDir = 'desc', string $sortBy = 'created'): LengthAwarePaginator;

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array;

    public function findByUuidOrFail(string $uuid): PurchaseOrder;

    public function countItems(int $purchaseOrderId): int;

    public function deleteItemsForPurchaseOrder(int $purchaseOrderId): int;

    /**
     * @return array<int, string>
     */
    public function listItemSkusByUuid(string $uuid): array;

    /**
     * @return array<int, int>
     */
    public function listProductIdsByUuid(string $uuid): array;

    public function delete(PurchaseOrder $po): void;
}



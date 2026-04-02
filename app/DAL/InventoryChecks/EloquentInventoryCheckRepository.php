<?php

declare(strict_types=1);

namespace App\DAL\InventoryChecks;

use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class EloquentInventoryCheckRepository implements InventoryCheckRepository
{
    public function create(InventoryCheck $check): InventoryCheck
    {
        $check->save();

        return $check;
    }

    public function createItem(InventoryCheckItem $item): InventoryCheckItem
    {
        $item->save();

        return $item;
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return InventoryCheck::query()
            ->withCount([
                'items as items_count',
                'items as matched_count' => fn ($q) => $q->where('match_status', '=', 'matched'),
                'items as unmatched_count' => fn ($q) => $q->where('match_status', '=', 'unmatched'),
                'items as ambiguous_count' => fn ($q) => $q->where('match_status', '=', 'ambiguous'),
                'items as applied_count' => fn ($q) => $q->where('applied', '=', 1),
            ])
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage);
    }

    public function findByUuidOrFail(string $uuid): InventoryCheck
    {
        /** @var InventoryCheck|null $check */
        $check = InventoryCheck::query()
            ->with([
                'items' => fn ($q) => $q->orderBy('id'),
                'items.product',
            ])
            ->withCount([
                'items as items_count',
                'items as matched_count' => fn ($q) => $q->where('match_status', '=', 'matched'),
                'items as unmatched_count' => fn ($q) => $q->where('match_status', '=', 'unmatched'),
                'items as ambiguous_count' => fn ($q) => $q->where('match_status', '=', 'ambiguous'),
                'items as applied_count' => fn ($q) => $q->where('applied', '=', 1),
            ])
            ->where('uuid', '=', $uuid)
            ->first();

        if ($check === null) {
            throw (new ModelNotFoundException)->setModel(InventoryCheck::class, [$uuid]);
        }

        return $check;
    }

    public function save(InventoryCheck $check): InventoryCheck
    {
        $check->save();

        return $check;
    }

    public function saveItem(InventoryCheckItem $item): InventoryCheckItem
    {
        $item->save();

        return $item;
    }

    public function findItemInSessionOrFail(InventoryCheck $session, int $itemId): InventoryCheckItem
    {
        /** @var InventoryCheckItem|null $item */
        $item = InventoryCheckItem::query()
            ->where('inventory_check_id', '=', (int) $session->id)
            ->where('id', '=', $itemId)
            ->first();
        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(InventoryCheckItem::class, [$itemId]);
        }

        return $item;
    }

    public function deleteItem(InventoryCheckItem $item): void
    {
        $item->delete();
    }
}



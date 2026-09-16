<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockItem;
use App\Models\PlamodPreorder;
use App\Services\StorePreorders\Exceptions\StorePreorderOpenException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PlamodPreorderLiveWindow
{
    public function todayYmd(): string
    {
        return now('America/Toronto')->toDateString();
    }

    public function isWindowOpen(mixed $poDueDate): bool
    {
        if ($poDueDate === null) {
            return true;
        }

        $date = $poDueDate instanceof Carbon
            ? $poDueDate->toDateString()
            : trim((string) $poDueDate);

        return $date !== '' && $date >= $this->todayYmd();
    }

    public function hasPreorderPrice(mixed $price): bool
    {
        return $price !== null && trim((string) $price) !== '';
    }

    public function isInStock(string $sku): bool
    {
        return PlamodInstockItem::query()->where('sku', '=', trim($sku))->exists();
    }

    public function isConfirmedLivePreorder(PlamodPreorder $row): bool
    {
        return $this->hasPreorderPrice($row->price_preorder)
            && $this->isWindowOpen($row->po_due_date)
            && ! $this->isInStock(trim((string) $row->sku));
    }

    /** @param Builder<PlamodPreorder> $query */
    public function constrainToLivePickList(Builder $query): void
    {
        $today = $this->todayYmd();
        $query->whereNotNull('price_preorder')
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('po_due_date')->orWhereDate('po_due_date', '>=', $today);
            })
            ->whereNotIn('sku', PlamodInstockItem::query()->select('sku'));
    }

    /** @param Builder<PlamodPreorder> $query */
    public function constrainToFutureReleases(Builder $query): void
    {
        $query->whereNotNull('release_date')
            ->whereDate('release_date', '>=', $this->todayYmd());
    }

    public function assertCanOpen(PlamodPreorder $row): void
    {
        $sku = trim((string) $row->sku);
        if (! $this->hasPreorderPrice($row->price_preorder)) {
            throw new StorePreorderOpenException(
                $sku.' is a Plamod stock listing — not a live preorder.',
            );
        }

        if ($this->isInStock($sku)) {
            throw new StorePreorderOpenException(
                $sku.' is on Plamod in-stock now — not a live preorder.',
            );
        }

        if (! $this->isWindowOpen($row->po_due_date)) {
            throw new StorePreorderOpenException(
                $sku.' Plamod preorder already closed.',
            );
        }
    }
}

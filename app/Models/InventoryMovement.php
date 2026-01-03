<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int $inventory_lot_id
 * @property string $kind
 * @property int $qty_delta
 * @property string|null $reference_type
 * @property string|null $reference_uuid
 * @property \Illuminate\Support\Carbon $occurred_at
 */
final class InventoryMovement extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'product_id',
        'inventory_lot_id',
        'kind',
        'qty_delta',
        'reference_type',
        'reference_uuid',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'qty_delta' => 'integer',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $movement): void {
            if (($movement->uuid ?? '') === '') {
                $movement->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Product, self> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<InventoryLot, self> */
    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }
}



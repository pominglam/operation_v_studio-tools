<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int|null $purchase_order_item_id
 * @property string $source_type
 * @property string|null $unit_cost
 * @property string|null $shipping_per_unit
 * @property int|null $qty_received
 * @property int $qty_remaining
 * @property \Illuminate\Support\Carbon|null $received_at
 */
final class InventoryLot extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'product_id',
        'purchase_order_item_id',
        'source_type',
        'unit_cost',
        'shipping_per_unit',
        'qty_received',
        'qty_remaining',
        'received_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'unit_cost' => 'decimal:4',
        'shipping_per_unit' => 'decimal:6',
        'qty_received' => 'integer',
        'qty_remaining' => 'integer',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $lot): void {
            if (($lot->uuid ?? '') === '') {
                $lot->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Product, self> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PurchaseOrderItem, self> */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /** @return HasMany<InventoryMovement> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}

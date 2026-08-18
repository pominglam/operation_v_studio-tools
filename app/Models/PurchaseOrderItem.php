<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $product_id
 * @property string $sku
 * @property string|null $product_name
 * @property string|null $barcode
 * @property string $vendor
 * @property string|null $unit_cost
 * @property string|null $vendor_unit_cost
 * @property int|null $qty_ordered
 * @property int|null $qty_shipped
 * @property int|null $qty_received
 */
final class PurchaseOrderItem extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'sku',
        'product_name',
        'barcode',
        'vendor',
        'unit_cost',
        'vendor_unit_cost',
        'qty_ordered',
        'qty_shipped',
        'qty_received',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'unit_cost' => 'decimal:2',
        'vendor_unit_cost' => 'decimal:4',
        'qty_ordered' => 'integer',
        'qty_shipped' => 'integer',
        'qty_received' => 'integer',
    ];

    /** @return BelongsTo<PurchaseOrder, self> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Product, self> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<InventoryLot> */
    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }
}

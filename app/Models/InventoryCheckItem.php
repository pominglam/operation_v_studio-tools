<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_check_id
 * @property int|null $product_id
 * @property string|null $barcode_scanned
 * @property string|null $handle
 * @property string|null $vendor
 * @property string $sku
 * @property string|null $type
 * @property string|null $product_name
 * @property string|null $english_name
 * @property int|null $available_amount
 * @property int|null $quantity_in_store
 * @property int|null $difference
 * @property string|null $notes
 * @property string $match_status
 * @property string|null $match_error
 * @property bool $issue_flag
 * @property string|null $issue_reason
 * @property bool $applied
 * @property \Illuminate\Support\Carbon|null $applied_at
 * @property string|null $selling_price_snapshot
 * @property string|null $landed_unit_cost_snapshot
 */
final class InventoryCheckItem extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'inventory_check_id',
        'product_id',
        'barcode_scanned',
        'handle',
        'vendor',
        'sku',
        'type',
        'product_name',
        'english_name',
        'available_amount',
        'quantity_in_store',
        'difference',
        'notes',
        'match_status',
        'match_error',
        'issue_flag',
        'issue_reason',
        'selling_price_snapshot',
        'landed_unit_cost_snapshot',
        'applied',
        'applied_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'available_amount' => 'integer',
        'quantity_in_store' => 'integer',
        'difference' => 'integer',
        'selling_price_snapshot' => 'decimal:2',
        'landed_unit_cost_snapshot' => 'decimal:2',
        'issue_flag' => 'boolean',
        'applied' => 'boolean',
        'applied_at' => 'datetime',
    ];

    /** @return BelongsTo<InventoryCheck, InventoryCheckItem> */
    public function inventoryCheck(): BelongsTo
    {
        return $this->belongsTo(InventoryCheck::class, 'inventory_check_id');
    }

    /** @return BelongsTo<Product, InventoryCheckItem> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

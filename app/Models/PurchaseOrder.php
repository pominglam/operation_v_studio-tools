<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $vendor
 * @property string|null $shipment_method
 * @property string|null $supplier_order_id
 * @property string $vendor_currency_code
 * @property string|null $ordered_date
 * @property string|null $shipped_date
 * @property string|null $estimated_arrival_date
 * @property string|null $received_date
 * @property string|null $fully_on_shelves_date
 * @property string|null $shipping_total
 * @property string|null $surcharge_total
 * @property string|null $product_total
 * @property string|null $vendor_product_total
 * @property string|null $fx_rate_to_cad
 * @property string|null $notes
 * @property bool $is_done
 * @property bool $exclude_from_latest_arrivals_ordering
 */
final class PurchaseOrder extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'vendor',
        'shipment_method',
        'supplier_order_id',
        'vendor_currency_code',
        'ordered_date',
        'shipped_date',
        'estimated_arrival_date',
        'received_date',
        'fully_on_shelves_date',
        'shipping_total',
        'surcharge_total',
        'product_total',
        'vendor_product_total',
        'fx_rate_to_cad',
        'notes',
        'is_done',
        'exclude_from_latest_arrivals_ordering',
        'workflow_checklist_json',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'ordered_date' => 'date',
        'shipped_date' => 'date',
        'estimated_arrival_date' => 'date',
        'received_date' => 'date',
        'fully_on_shelves_date' => 'date',
        'shipping_total' => 'decimal:2',
        'surcharge_total' => 'decimal:2',
        'product_total' => 'decimal:2',
        'vendor_product_total' => 'decimal:2',
        'fx_rate_to_cad' => 'decimal:6',
        'is_done' => 'boolean',
        'exclude_from_latest_arrivals_ordering' => 'boolean',
        'workflow_checklist_json' => 'array',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $po): void {
            if (($po->uuid ?? '') === '') {
                $po->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<PurchaseOrderItem> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}

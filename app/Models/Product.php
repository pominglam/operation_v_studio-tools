<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $sku
 * @property string|null $barcode
 * @property string $description
 * @property string|null $type
 * @property string|null $price
 * @property int|null $order_qty
 * @property int|null $filled_qty
 * @property string|null $extended
 * @property \Illuminate\Support\Carbon|null $price_researched_at
 */
final class Product extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'sku',
        'barcode',
        'description',
        'type',
        'price',
        'order_qty',
        'filled_qty',
        'extended',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'price' => 'decimal:2',
        'order_qty' => 'integer',
        'filled_qty' => 'integer',
        'extended' => 'decimal:2',
        'price_researched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $product): void {
            if (($product->uuid ?? '') === '') {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<ProductPriceQuote> */
    public function priceQuotes(): HasMany
    {
        return $this->hasMany(ProductPriceQuote::class);
    }

    /** @return HasOne<ProductSellingPrice> */
    public function sellingPrice(): HasOne
    {
        return $this->hasOne(ProductSellingPrice::class);
    }
}

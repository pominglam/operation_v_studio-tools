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
 * @property string|null $handle
 * @property string|null $type
 * @property string|null $vendor
 * @property bool $published_on_shopify
 * @property int|null $order_qty
 * @property int|null $filled_qty
 * @property int|null $available_qty
 * @property string|null $extended
 * @property string|null $latest_unit_cost
 * @property string|null $latest_landed_unit_cost
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
        'handle',
        'type',
        'vendor',
        'published_on_shopify',
        'order_qty',
        'filled_qty',
        'available_qty',
        'extended',
        'latest_unit_cost',
        'latest_landed_unit_cost',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'order_qty' => 'integer',
        'filled_qty' => 'integer',
        'available_qty' => 'integer',
        'published_on_shopify' => 'boolean',
        'extended' => 'decimal:2',
        'latest_unit_cost' => 'decimal:2',
        'latest_landed_unit_cost' => 'decimal:2',
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

    /** @return HasMany<ProductExternalContent> */
    public function externalContents(): HasMany
    {
        return $this->hasMany(ProductExternalContent::class);
    }

    /** @return HasMany<ProductExternalAsset> */
    public function externalAssets(): HasMany
    {
        return $this->hasMany(ProductExternalAsset::class);
    }

    /** @return HasOne<ProductExternalContent> */
    public function hljExternalContent(): HasOne
    {
        return $this->hasOne(ProductExternalContent::class)->where('source', '=', 'hlj');
    }

    /** @return HasOne<ProductExternalContent> */
    public function plamodExternalContent(): HasOne
    {
        return $this->hasOne(ProductExternalContent::class)->where('source', '=', 'plamod');
    }

    /** @return HasMany<ProductExternalAsset> */
    public function plamodImageAssets(): HasMany
    {
        return $this->hasMany(ProductExternalAsset::class)
            ->where('source', '=', 'plamod')
            ->where(function ($q): void {
                $q->where('kind', '=', 'image')
                    ->orWhere('mime_type', 'like', 'image/%');
            })
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}

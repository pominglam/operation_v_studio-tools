<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $preferred_description_source
 * @property string|null $handle
 * @property string $main_type
 * @property string|null $department
 * @property string|null $type
 * @property string|null $manufacturer
 * @property string|null $franchise
 * @property string|null $product_line
 * @property string|null $subline
 * @property string|null $grade
 * @property string|null $series
 * @property string|null $scale
 * @property string|null $workshop_shelf
 * @property array<string, string|array<int, string>>|null $workshop_facets
 * @property string|null $accessory_kind
 * @property int|null $yen_price
 * @property \Illuminate\Support\Carbon|null $bandai_launch_date
 * @property string|null $vendor
 * @property string|null $brand
 * @property bool $published_on_shopify
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property bool $is_ready
 * @property bool $latest_arrival
 * @property bool $is_critical
 * @property bool $is_discontinued
 * @property bool $is_hazardous_shipment
 * @property string|null $shipment_method
 * @property int|null $order_qty
 * @property int|null $filled_qty
 * @property int|null $available_qty
 * @property int|null $hold_qty
 * @property int|null $maintain_qty
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
        'preferred_description_source',
        'handle',
        'main_type',
        'department',
        'type',
        'manufacturer',
        'franchise',
        'product_line',
        'subline',
        'grade',
        'series',
        'scale',
        'workshop_shelf',
        'workshop_facets',
        'accessory_kind',
        'yen_price',
        'bandai_launch_date',
        'vendor',
        'brand',
        'published_on_shopify',
        'archived_at',
        'is_ready',
        'latest_arrival',
        'is_critical',
        'is_discontinued',
        'is_hazardous_shipment',
        'shipment_method',
        'order_qty',
        'filled_qty',
        'available_qty',
        'hold_qty',
        'maintain_qty',
        'extended',
        'latest_unit_cost',
        'latest_landed_unit_cost',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'order_qty' => 'integer',
        'filled_qty' => 'integer',
        'available_qty' => 'integer',
        'hold_qty' => 'integer',
        'maintain_qty' => 'integer',
        'yen_price' => 'integer',
        'bandai_launch_date' => 'date',
        'published_on_shopify' => 'boolean',
        'archived_at' => 'datetime',
        'is_ready' => 'boolean',
        'latest_arrival' => 'boolean',
        'is_critical' => 'boolean',
        'is_discontinued' => 'boolean',
        'is_hazardous_shipment' => 'boolean',
        'extended' => 'decimal:2',
        'latest_unit_cost' => 'decimal:2',
        'latest_landed_unit_cost' => 'decimal:2',
        'price_researched_at' => 'datetime',
        'workshop_facets' => 'array',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $product): void {
            if (($product->uuid ?? '') === '') {
                $product->uuid = (string) Str::uuid();
            }
        });

        self::saving(function (self $product): void {
            if ($product->isDirty('available_qty') && $product->available_qty !== null) {
                $product->available_qty = max(0, (int) $product->available_qty);
            }
            if ($product->isDirty('hold_qty') && $product->hold_qty !== null) {
                $product->hold_qty = max(0, (int) $product->hold_qty);
            }
        });
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
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

    /** @return HasMany<ProductTaxonomyVerification> */
    public function taxonomyVerifications(): HasMany
    {
        return $this->hasMany(ProductTaxonomyVerification::class);
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

    /** @return HasMany<ProductExternalAsset> */
    public function imageAssets(): HasMany
    {
        return $this->hasMany(ProductExternalAsset::class)
            ->where(function ($q): void {
                $q->where('kind', '=', 'image')
                    ->orWhere('mime_type', 'like', 'image/%');
            })
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return HasMany<ProductExternalAsset> */
    public function shopifyImageAssets(): HasMany
    {
        return $this->hasMany(ProductExternalAsset::class)
            ->where('shopify_enabled', '=', true)
            ->where(function ($q): void {
                $q->where('kind', '=', 'image')
                    ->orWhere('mime_type', 'like', 'image/%');
            })
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}

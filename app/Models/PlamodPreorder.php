<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sku
 * @property string|null $barcode
 * @property string $product_name
 * @property string|null $series
 * @property \Illuminate\Support\Carbon|null $release_date
 * @property string|null $manufacturer
 * @property string|null $category
 * @property string|null $price_stock
 * @property string|null $price_preorder
 * @property string|null $price_backorder
 * @property int|null $quantity_preorder
 * @property \Illuminate\Support\Carbon|null $po_due_date
 * @property \Illuminate\Support\Carbon|null $eta_date
 * @property string|null $source_image_url
 * @property string|null $image_storage_path
 * @property string $image_download_status
 * @property \Illuminate\Support\Carbon|null $image_downloaded_at
 * @property \Illuminate\Support\Carbon|null $dropped_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
final class PlamodPreorder extends Model
{
    public const string IMAGE_STATUS_PENDING = 'pending';

    public const string IMAGE_STATUS_COMPLETED = 'completed';

    public const string IMAGE_STATUS_FAILED = 'failed';

    /** @var array<int, string> */
    protected $fillable = [
        'sku',
        'barcode',
        'product_name',
        'series',
        'release_date',
        'manufacturer',
        'category',
        'price_stock',
        'price_preorder',
        'price_backorder',
        'quantity_preorder',
        'po_due_date',
        'eta_date',
        'source_image_url',
        'image_storage_path',
        'image_download_status',
        'image_downloaded_at',
        'dropped_at',
        'last_seen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'po_due_date' => 'date',
            'eta_date' => 'date',
            'image_downloaded_at' => 'datetime',
            'dropped_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dropped_at');
    }
}

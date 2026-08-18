<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sku
 * @property string|null $barcode
 * @property string $product_name
 * @property string|null $series
 * @property string|null $release_date
 * @property string|null $release_date_label
 * @property string|null $manufacturer
 * @property string|null $category
 * @property string|null $price_stock
 * @property string|null $source_image_url
 * @property string|null $plamod_pdp_url
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property int|null $sync_log_id
 */
final class PlamodInstockItem extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'sku',
        'barcode',
        'product_name',
        'series',
        'release_date',
        'release_date_label',
        'manufacturer',
        'category',
        'price_stock',
        'source_image_url',
        'plamod_pdp_url',
        'last_seen_at',
        'sync_log_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'release_date' => 'date',
        'price_stock' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'sync_log_id' => 'integer',
    ];
}

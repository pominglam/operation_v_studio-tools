<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $product_uuid
 * @property string $sku
 * @property string $site_key
 * @property string $site_name
 * @property string|null $status
 * @property string|null $availability
 * @property string|null $currency
 * @property string|null $price
 * @property string|null $original_price
 * @property string|null $product_url
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $fetched_at
 * @property string|null $run_uuid
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $handled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class PriceResearchQuoteReport extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'product_id',
        'product_uuid',
        'sku',
        'site_key',
        'site_name',
        'status',
        'availability',
        'currency',
        'price',
        'original_price',
        'product_url',
        'error_message',
        'fetched_at',
        'run_uuid',
        'note',
        'handled_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'fetched_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    /** @return BelongsTo<Product, PriceResearchQuoteReport> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

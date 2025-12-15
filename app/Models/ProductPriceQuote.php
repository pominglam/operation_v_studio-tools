<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $site_key
 * @property string $site_name
 * @property string $status
 * @property string|null $availability
 * @property string $currency
 * @property string|null $price
 * @property string|null $original_price
 * @property string|null $product_url
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $fetched_at
 */
final class ProductPriceQuote extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'product_id',
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
    ];

    /** @var array<string, string> */
    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'fetched_at' => 'datetime',
    ];

    /** @return BelongsTo<Product, ProductPriceQuote> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $product_uuid
 * @property string|null $selling_price
 * @property string $currency
 */
final class ProductSellingPrice extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'product_id',
        'product_uuid',
        'selling_price',
        'currency',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'selling_price' => 'decimal:2',
    ];

    /** @return BelongsTo<Product, ProductSellingPrice> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

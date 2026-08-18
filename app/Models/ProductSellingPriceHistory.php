<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $product_uuid
 * @property string|null $previous_price
 * @property string|null $new_price
 * @property string $currency
 * @property string $source
 * @property int|null $purchase_order_id
 */
final class ProductSellingPriceHistory extends Model
{
    protected $table = 'product_selling_price_history';

    public const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $fillable = [
        'product_id',
        'product_uuid',
        'previous_price',
        'new_price',
        'currency',
        'source',
        'purchase_order_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'previous_price' => 'decimal:2',
        'new_price' => 'decimal:2',
    ];

    /** @return BelongsTo<Product, self> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PurchaseOrder, self> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}

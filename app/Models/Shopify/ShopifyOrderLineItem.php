<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopifyOrderLineItem extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_on' => 'date',
            'payload_json' => 'array',
        ];
    }

    /** @return BelongsTo<Product, self> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ShopifyOrder, self> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'order_gid', 'gid');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDemandEligible(Builder $query): Builder
    {
        return app(\App\Services\Shopify\Admin\Orders\ShopifyOrderDemandEligibility::class)
            ->scopeDemandEligibleLineItems($query);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use App\Models\StoreEvent;
use App\Models\StorePreorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $gid
 * @property array<string, mixed>|null $payload_json
 * @property list<string>|null $payment_gateway_names
 * @property bool $has_store_preorder
 */
final class ShopifyOrder extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<StoreEvent, ShopifyOrder> */
    public function storeEvent(): BelongsTo
    {
        return $this->belongsTo(StoreEvent::class, 'store_event_id');
    }

    /** @return HasMany<ShopifyOrderLineItem> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyOrderLineItem::class, 'order_gid', 'gid');
    }

    /** @return HasMany<ShopifyOrderLineItem> */
    public function storePreorderLines(): HasMany
    {
        return $this->lineItems()->where(function ($query): void {
            $query->whereIn('product_id', StorePreorder::query()->select('product_id'))
                ->orWhereIn('sku', StorePreorder::query()->select('plamod_sku'));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'payment_gateway_names' => 'array',
            'graphql_updated_at' => 'datetime',
            'ordered_at_shop_tz' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}

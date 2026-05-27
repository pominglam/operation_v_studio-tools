<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Model;

final class ShopifyOrder extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'graphql_updated_at' => 'datetime',
            'ordered_at_shop_tz' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}

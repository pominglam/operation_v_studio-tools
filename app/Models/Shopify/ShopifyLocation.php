<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Model;

final class ShopifyLocation extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fulfills_online_orders' => 'boolean',
            'payload_json' => 'array',
            'graphql_updated_at' => 'datetime',
        ];
    }
}

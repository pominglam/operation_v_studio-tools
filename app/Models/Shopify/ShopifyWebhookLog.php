<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Model;

final class ShopifyWebhookLog extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verification_ok' => 'boolean',
            'payload_json' => 'array',
        ];
    }
}

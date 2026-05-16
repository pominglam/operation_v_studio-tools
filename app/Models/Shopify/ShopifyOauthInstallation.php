<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Model;

final class ShopifyOauthInstallation extends Model
{
    protected $table = 'shopify_oauth_installations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'oauth_updated_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Model;

final class ShopifySyncState extends Model
{
    protected $table = 'shopify_sync_state';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'high_water_updated_at' => 'datetime',
            'last_run_started_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Shopify\ShopifyOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon $ends_on
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 */
final class StoreEvent extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'name',
        'starts_on',
        'ends_on',
        'notes',
        'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<ShopifyOrder> */
    public function orders(): HasMany
    {
        return $this->hasMany(ShopifyOrder::class, 'store_event_id');
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            if (($event->uuid ?? '') === '') {
                $event->uuid = (string) Str::uuid();
            }
        });
    }
}

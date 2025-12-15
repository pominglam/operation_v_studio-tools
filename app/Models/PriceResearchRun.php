<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property bool $force
 * @property int $ttl_days
 * @property array<int, string>|null $product_uuids
 * @property array<int, string>|null $site_keys
 * @property int $total_products
 * @property int $processed_products
 * @property int $refreshed_products
 * @property int $skipped_fresh_products
 * @property int $total_sites
 * @property int $processed_sites
 * @property int $quotes_written
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property string|null $error_message
 */
final class PriceResearchRun extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'status',
        'force',
        'ttl_days',
        'product_uuids',
        'site_keys',
        'total_products',
        'processed_products',
        'refreshed_products',
        'skipped_fresh_products',
        'total_sites',
        'processed_sites',
        'quotes_written',
        'started_at',
        'finished_at',
        'error_message',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'force' => 'boolean',
        'ttl_days' => 'integer',
        'product_uuids' => 'array',
        'site_keys' => 'array',
        'total_products' => 'integer',
        'processed_products' => 'integer',
        'refreshed_products' => 'integer',
        'skipped_fresh_products' => 'integer',
        'total_sites' => 'integer',
        'processed_sites' => 'integer',
        'quotes_written' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            if (($run->uuid ?? '') === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }
}

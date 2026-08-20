<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $tracking_key
 * @property string $tracking_number
 * @property string $status
 * @property string|null $provider
 * @property string|null $tracking_url
 * @property int $attempt_count
 * @property \Illuminate\Support\Carbon|null $last_attempted_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $retry_after
 * @property string|null $error_summary
 */
final class ShipmentTrackingResolution extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'tracking_key',
        'tracking_number',
        'status',
        'provider',
        'tracking_url',
        'attempt_count',
        'last_attempted_at',
        'resolved_at',
        'retry_after',
        'error_summary',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'attempt_count' => 'integer',
        'last_attempted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'retry_after' => 'datetime',
    ];
}

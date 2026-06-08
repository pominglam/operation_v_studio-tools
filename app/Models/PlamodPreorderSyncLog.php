<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $counts_json
 * @property string|null $error_summary
 */
final class PlamodPreorderSyncLog extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'counts_json',
        'error_summary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts_json' => 'array',
        ];
    }
}

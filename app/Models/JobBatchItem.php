<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class JobBatchItem extends Model
{
    protected $table = 'job_batch_items';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'batch_id',
        'product_uuid',
        'sku',
        'vendor',
        'status',
        'attempts',
        'sync_uuid',
        'last_error',
        'started_at',
        'finished_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempts' => 'int',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}



<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property string $run_uuid
 * @property int $product_id
 * @property string $product_uuid
 * @property string $sku
 * @property string $site_key
 * @property string $site_name
 * @property string $status
 * @property string|null $product_url
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class PriceResearchRunLog extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'run_id',
        'run_uuid',
        'product_id',
        'product_uuid',
        'sku',
        'site_key',
        'site_name',
        'status',
        'product_url',
        'error_message',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    /** @return BelongsTo<PriceResearchRun, PriceResearchRunLog> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PriceResearchRun::class, 'run_id');
    }

    /** @return BelongsTo<Product, PriceResearchRunLog> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

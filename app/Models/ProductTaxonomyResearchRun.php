<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string $research_version
 * @property array<string, mixed>|null $checkpoint_json
 * @property array<string, mixed>|null $counts_json
 * @property string|null $error_summary
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
final class ProductTaxonomyResearchRun extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'status',
        'research_version',
        'checkpoint_json',
        'counts_json',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checkpoint_json' => 'array',
            'counts_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            if (($run->uuid ?? '') === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<ProductTaxonomyVerification> */
    public function taxonomyVerifications(): HasMany
    {
        return $this->hasMany(ProductTaxonomyVerification::class, 'research_run_id');
    }
}

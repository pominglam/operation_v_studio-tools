<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int|null $research_run_id
 * @property string $status
 * @property string $research_version
 * @property array<string, string|null> $proposed_values_json
 * @property array<string, string|null> $previous_values_json
 * @property array<string, mixed> $evidence_json
 * @property int $overall_confidence
 * @property string $research_method
 * @property string|null $operator_notes
 * @property \Illuminate\Support\Carbon $researched_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $overridden_at
 * @property string|null $verified_by
 * @property-read Product $product
 * @property-read ProductTaxonomyResearchRun|null $researchRun
 */
final class ProductTaxonomyVerification extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'product_id',
        'research_run_id',
        'status',
        'research_version',
        'proposed_values_json',
        'previous_values_json',
        'evidence_json',
        'overall_confidence',
        'research_method',
        'operator_notes',
        'researched_at',
        'verified_at',
        'overridden_at',
        'verified_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'proposed_values_json' => 'array',
            'previous_values_json' => 'array',
            'evidence_json' => 'array',
            'overall_confidence' => 'integer',
            'researched_at' => 'datetime',
            'verified_at' => 'datetime',
            'overridden_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $verification): void {
            if (($verification->uuid ?? '') === '') {
                $verification->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Product, ProductTaxonomyVerification> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductTaxonomyResearchRun, ProductTaxonomyVerification> */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ProductTaxonomyResearchRun::class, 'research_run_id');
    }
}

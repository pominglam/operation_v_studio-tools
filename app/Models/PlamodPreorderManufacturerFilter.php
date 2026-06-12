<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlamodPreorderManufacturerFilterDecision;
use App\Enums\PlamodPreorderManufacturerFilterType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $manufacturer_id
 * @property PlamodPreorderManufacturerFilterType $filter_type
 * @property string $name
 * @property int|null $plamod_preorder_count
 * @property int|null $plamod_other_count
 * @property PlamodPreorderManufacturerFilterDecision $decision
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
final class PlamodPreorderManufacturerFilter extends Model
{
    protected $table = 'plamod_preorder_manufacturer_filters';

    /** @var array<int, string> */
    protected $fillable = [
        'manufacturer_id',
        'filter_type',
        'name',
        'plamod_preorder_count',
        'plamod_other_count',
        'decision',
        'last_seen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manufacturer_id' => 'integer',
            'filter_type' => PlamodPreorderManufacturerFilterType::class,
            'plamod_preorder_count' => 'integer',
            'plamod_other_count' => 'integer',
            'decision' => PlamodPreorderManufacturerFilterDecision::class,
            'last_seen_at' => 'datetime',
        ];
    }
}

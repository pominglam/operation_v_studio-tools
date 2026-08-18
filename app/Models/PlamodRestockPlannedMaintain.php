<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sku
 * @property int $maintain_qty
 * @property \Illuminate\Support\Carbon|null $applied_at
 */
final class PlamodRestockPlannedMaintain extends Model
{
    protected $table = 'plamod_restock_planned_maintain';

    protected $fillable = [
        'sku',
        'maintain_qty',
        'applied_at',
    ];

    protected $casts = [
        'maintain_qty' => 'integer',
        'applied_at' => 'datetime',
    ];
}

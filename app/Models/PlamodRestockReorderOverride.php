<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sku
 * @property int $reorder_qty
 */
final class PlamodRestockReorderOverride extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'sku',
        'reorder_qty',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'reorder_qty' => 'integer',
    ];
}

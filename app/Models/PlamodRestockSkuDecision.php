<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlamodRestockSkuDecisionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sku
 * @property PlamodRestockSkuDecisionStatus $status
 * @property int|null $order_qty
 */
final class PlamodRestockSkuDecision extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'sku',
        'status',
        'order_qty',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => PlamodRestockSkuDecisionStatus::class,
        'order_qty' => 'integer',
    ];
}

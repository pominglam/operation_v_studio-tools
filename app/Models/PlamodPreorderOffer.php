<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sku
 * @property string $offer_key
 * @property string|null $offer_id
 * @property int $quantity
 * @property \Illuminate\Support\Carbon|null $eta_date
 * @property \Illuminate\Support\Carbon|null $po_due_date
 * @property string|null $price_preorder
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
final class PlamodPreorderOffer extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'sku',
        'offer_key',
        'offer_id',
        'quantity',
        'eta_date',
        'po_due_date',
        'price_preorder',
        'last_seen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'eta_date' => 'date',
            'po_due_date' => 'date',
            'last_seen_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property string $plamod_sku
 * @property string $status
 * @property string $deposit_percent
 * @property int|null $cap_qty
 * @property string|null $selling_price_cad
 * @property string|null $po_cost_cad
 * @property \Illuminate\Support\Carbon|null $window_ends_on
 * @property string|null $eta_date
 * @property \Illuminate\Support\Carbon $opened_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 */
final class StorePreorder extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'product_id',
        'plamod_sku',
        'status',
        'deposit_percent',
        'cap_qty',
        'selling_price_cad',
        'po_cost_cad',
        'window_ends_on',
        'eta_date',
        'opened_at',
        'closed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deposit_percent' => 'decimal:2',
            'selling_price_cad' => 'decimal:2',
            'po_cost_cad' => 'decimal:2',
            'cap_qty' => 'integer',
            'window_ends_on' => 'date',
            'eta_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $offer): void {
            if (($offer->uuid ?? '') === '') {
                $offer->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isOpen(): bool
    {
        return $this->status === StorePreorderStatus::OPEN;
    }

    public function remainingCapQty(): ?int
    {
        if ($this->cap_qty === null) {
            return null;
        }

        if (! $this->isOpen()) {
            return 0;
        }

        return max(0, (int) $this->cap_qty);
    }
}

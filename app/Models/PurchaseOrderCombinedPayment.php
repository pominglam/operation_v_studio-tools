<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $vendor_currency_code
 * @property string $vendor_total
 * @property string $total_paid_cad
 * @property string $fx_rate_to_cad
 * @property bool $includes_shipping
 */
final class PurchaseOrderCombinedPayment extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'vendor_currency_code',
        'vendor_total',
        'total_paid_cad',
        'fx_rate_to_cad',
        'includes_shipping',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'vendor_total' => 'decimal:2',
        'total_paid_cad' => 'decimal:2',
        'fx_rate_to_cad' => 'decimal:6',
        'includes_shipping' => 'boolean',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $payment): void {
            if (($payment->uuid ?? '') === '') {
                $payment->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<PurchaseOrderCombinedPaymentLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderCombinedPaymentLine::class);
    }
}

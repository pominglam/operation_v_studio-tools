<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $sku
 * @property string|null $barcode
 * @property string $description
 * @property string|null $type
 * @property string|null $price
 * @property int|null $order_qty
 * @property int|null $filled_qty
 * @property string|null $extended
 */
final class Product extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'sku',
        'barcode',
        'description',
        'type',
        'price',
        'order_qty',
        'filled_qty',
        'extended',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'price' => 'decimal:2',
        'order_qty' => 'integer',
        'filled_qty' => 'integer',
        'extended' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (($product->uuid ?? '') === '') {
                $product->uuid = (string) Str::uuid();
            }
        });
    }
}



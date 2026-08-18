<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_combined_payment_id
 * @property int $purchase_order_id
 * @property string $vendor_product_total
 * @property string|null $vendor_shipping_total
 * @property string $product_total_cad
 * @property string|null $shipping_total_cad
 */
final class PurchaseOrderCombinedPaymentLine extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'purchase_order_combined_payment_id',
        'purchase_order_id',
        'vendor_product_total',
        'vendor_shipping_total',
        'product_total_cad',
        'shipping_total_cad',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'vendor_product_total' => 'decimal:2',
        'vendor_shipping_total' => 'decimal:2',
        'product_total_cad' => 'decimal:2',
        'shipping_total_cad' => 'decimal:2',
    ];

    /** @return BelongsTo<PurchaseOrderCombinedPayment, self> */
    public function combinedPayment(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderCombinedPayment::class, 'purchase_order_combined_payment_id');
    }

    /** @return BelongsTo<PurchaseOrder, self> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}

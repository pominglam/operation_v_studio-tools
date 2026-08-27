<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $customer_contact_media
 * @property string $customer_contact_value
 * @property string $product_name
 * @property string|null $customer_visual_path
 * @property string|null $customer_visual_mime
 * @property string|null $customer_visual_filename
 * @property string|null $product_visual_path
 * @property string|null $product_visual_mime
 * @property string|null $product_visual_filename
 * @property string|null $merchandiser_order_proof_path
 * @property string|null $merchandiser_order_proof_mime
 * @property string|null $merchandiser_order_proof_filename
 * @property string|null $product_cost_amount
 * @property string|null $product_cost_currency
 * @property string|null $shipping_cost_amount
 * @property string|null $shipping_cost_currency
 * @property string|null $landed_cost_cad
 * @property string|null $product_fx_rate_to_cad
 * @property string|null $shipping_fx_rate_to_cad
 * @property \Illuminate\Support\Carbon|null $fx_rate_date
 * @property int|null $receive_delay_amount
 * @property string|null $receive_delay_unit
 * @property int|null $receive_delay_days
 * @property string|null $actual_product_cost_amount
 * @property string|null $actual_product_cost_currency
 * @property string|null $actual_shipping_cost_amount
 * @property string|null $actual_shipping_cost_currency
 * @property string|null $actual_landed_cost_cad
 * @property string|null $actual_product_fx_rate_to_cad
 * @property string|null $actual_shipping_fx_rate_to_cad
 * @property \Illuminate\Support\Carbon|null $actual_fx_rate_date
 * @property int|null $actual_receive_delay_amount
 * @property string|null $actual_receive_delay_unit
 * @property int|null $actual_receive_delay_days
 * @property \Illuminate\Support\Carbon|null $actual_arrival_at
 * @property string|null $merchandiser_price_multiplier
 * @property string|null $merchandiser_price_cad
 * @property string|null $merchandiser_commission_override_cad
 * @property string|null $our_price_multiplier
 * @property string|null $customer_price_cad
 * @property string|null $our_commission_override_cad
 * @property string|null $deposit_percent
 * @property string|null $deposit_amount_override_cad
 * @property \Illuminate\Support\Carbon|null $customer_offer_locked_at
 * @property \Illuminate\Support\Carbon|null $deposit_received_at
 * @property \Illuminate\Support\Carbon|null $merchandiser_ordered_at
 * @property \Illuminate\Support\Carbon|null $estimated_arrival_at
 * @property \Illuminate\Support\Carbon|null $product_received_at
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property string|null $competitor_prices_product_name
 * @property array<int, array<string, mixed>>|null $competitor_price_quotes_json
 * @property \Illuminate\Support\Carbon|null $competitor_prices_fetched_at
 * @property string|null $competitor_prices_refresh_status
 * @property string|null $competitor_prices_refresh_scope
 * @property string|null $competitor_prices_refresh_error
 * @property string|null $notes
 */
final class CustomAsiaOrder extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'customer_contact_media',
        'customer_contact_value',
        'product_name',
        'customer_visual_path',
        'customer_visual_mime',
        'customer_visual_filename',
        'product_visual_path',
        'product_visual_mime',
        'product_visual_filename',
        'merchandiser_order_proof_path',
        'merchandiser_order_proof_mime',
        'merchandiser_order_proof_filename',
        'product_cost_amount',
        'product_cost_currency',
        'shipping_cost_amount',
        'shipping_cost_currency',
        'landed_cost_cad',
        'product_fx_rate_to_cad',
        'shipping_fx_rate_to_cad',
        'fx_rate_date',
        'receive_delay_amount',
        'receive_delay_unit',
        'receive_delay_days',
        'actual_product_cost_amount',
        'actual_product_cost_currency',
        'actual_shipping_cost_amount',
        'actual_shipping_cost_currency',
        'actual_landed_cost_cad',
        'actual_product_fx_rate_to_cad',
        'actual_shipping_fx_rate_to_cad',
        'actual_fx_rate_date',
        'actual_receive_delay_amount',
        'actual_receive_delay_unit',
        'actual_receive_delay_days',
        'actual_arrival_at',
        'merchandiser_price_multiplier',
        'merchandiser_price_cad',
        'merchandiser_commission_override_cad',
        'our_price_multiplier',
        'customer_price_cad',
        'our_commission_override_cad',
        'deposit_percent',
        'deposit_amount_override_cad',
        'customer_offer_locked_at',
        'deposit_received_at',
        'merchandiser_ordered_at',
        'estimated_arrival_at',
        'product_received_at',
        'rejected_at',
        'competitor_prices_product_name',
        'competitor_price_quotes_json',
        'competitor_prices_fetched_at',
        'competitor_prices_refresh_status',
        'competitor_prices_refresh_scope',
        'competitor_prices_refresh_error',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'product_cost_amount' => 'decimal:2',
        'shipping_cost_amount' => 'decimal:2',
        'landed_cost_cad' => 'decimal:2',
        'product_fx_rate_to_cad' => 'decimal:6',
        'shipping_fx_rate_to_cad' => 'decimal:6',
        'fx_rate_date' => 'date',
        'receive_delay_amount' => 'integer',
        'receive_delay_days' => 'integer',
        'actual_product_cost_amount' => 'decimal:2',
        'actual_shipping_cost_amount' => 'decimal:2',
        'actual_landed_cost_cad' => 'decimal:2',
        'actual_product_fx_rate_to_cad' => 'decimal:6',
        'actual_shipping_fx_rate_to_cad' => 'decimal:6',
        'actual_fx_rate_date' => 'date',
        'actual_receive_delay_amount' => 'integer',
        'actual_receive_delay_days' => 'integer',
        'actual_arrival_at' => 'date',
        'merchandiser_price_multiplier' => 'decimal:2',
        'merchandiser_price_cad' => 'decimal:2',
        'merchandiser_commission_override_cad' => 'decimal:2',
        'our_price_multiplier' => 'decimal:2',
        'customer_price_cad' => 'decimal:2',
        'our_commission_override_cad' => 'decimal:2',
        'deposit_percent' => 'decimal:2',
        'deposit_amount_override_cad' => 'decimal:2',
        'customer_offer_locked_at' => 'datetime',
        'deposit_received_at' => 'datetime',
        'merchandiser_ordered_at' => 'datetime',
        'estimated_arrival_at' => 'date',
        'product_received_at' => 'datetime',
        'rejected_at' => 'datetime',
        'competitor_price_quotes_json' => 'array',
        'competitor_prices_fetched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $order): void {
            if (($order->uuid ?? '') === '') {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    public function isQuoted(): bool
    {
        return $this->product_cost_amount !== null
            && $this->shipping_cost_amount !== null
            && $this->landed_cost_cad !== null
            && $this->receive_delay_days !== null;
    }

    public function isPriced(): bool
    {
        if ($this->customer_price_cad === null) {
            return false;
        }

        return $this->deposit_percent !== null || $this->deposit_amount_override_cad !== null;
    }

    public function isOfferLocked(): bool
    {
        return $this->customer_offer_locked_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }
}

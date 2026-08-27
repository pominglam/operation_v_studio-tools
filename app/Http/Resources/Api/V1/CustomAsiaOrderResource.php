<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CustomAsiaOrder;
use App\Services\CustomOrders\CustomAsiaOrderPricingCapsService;
use App\Support\CustomOrders\CustomAsiaOrderCompetitorPriceSites;
use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use App\Support\CustomOrders\CustomAsiaOrderCurrency;
use App\Support\CustomOrders\CustomAsiaOrderCustomerPricing;
use App\Support\CustomOrders\CustomAsiaOrderReceiveDelayUnit;
use App\Support\CustomOrders\CustomAsiaOrderVisualKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomAsiaOrder */
final class CustomAsiaOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CustomAsiaOrderPricingCapsService $capsService */
        $capsService = app(CustomAsiaOrderPricingCapsService::class);
        $caps = $capsService->getCaps();

        return [
            'id' => $this->uuid,
            'customer_contact_media' => $this->customer_contact_media,
            'customer_contact_media_label' => CustomAsiaOrderContactMedia::label((string) $this->customer_contact_media),
            'customer_contact_value' => $this->customer_contact_value,
            'product_name' => $this->product_name,
            'customer_visual' => $this->visualPayload(CustomAsiaOrderVisualKind::CUSTOMER),
            'product_visual' => $this->visualPayload(CustomAsiaOrderVisualKind::PRODUCT),
            'merchandiser_order_proof_visual' => $this->visualPayload(CustomAsiaOrderVisualKind::MERCHANDISER_ORDER_PROOF),
            'product_cost_amount' => $this->product_cost_amount,
            'product_cost_currency' => $this->product_cost_currency,
            'product_cost_currency_label' => is_string($this->product_cost_currency)
                ? CustomAsiaOrderCurrency::label($this->product_cost_currency)
                : null,
            'shipping_cost_amount' => $this->shipping_cost_amount,
            'shipping_cost_currency' => $this->shipping_cost_currency,
            'shipping_cost_currency_label' => is_string($this->shipping_cost_currency)
                ? CustomAsiaOrderCurrency::label($this->shipping_cost_currency)
                : null,
            'landed_cost_cad' => $this->landed_cost_cad,
            'product_fx_rate_to_cad' => $this->product_fx_rate_to_cad,
            'shipping_fx_rate_to_cad' => $this->shipping_fx_rate_to_cad,
            'fx_rate_date' => $this->fx_rate_date?->toDateString(),
            'receive_delay_amount' => $this->receive_delay_amount,
            'receive_delay_unit' => $this->receive_delay_unit,
            'receive_delay_unit_label' => is_string($this->receive_delay_unit)
                ? CustomAsiaOrderReceiveDelayUnit::label($this->receive_delay_unit)
                : null,
            'receive_delay_days' => $this->receive_delay_days,
            'receive_delay_label' => CustomAsiaOrderReceiveDelayUnit::formatLabel(
                is_int($this->receive_delay_amount) ? $this->receive_delay_amount : null,
                is_string($this->receive_delay_unit) ? $this->receive_delay_unit : null,
            ),
            'actual_product_cost_amount' => $this->actual_product_cost_amount,
            'actual_product_cost_currency' => $this->actual_product_cost_currency,
            'actual_product_cost_currency_label' => is_string($this->actual_product_cost_currency)
                ? CustomAsiaOrderCurrency::label($this->actual_product_cost_currency)
                : null,
            'actual_shipping_cost_amount' => $this->actual_shipping_cost_amount,
            'actual_shipping_cost_currency' => $this->actual_shipping_cost_currency,
            'actual_shipping_cost_currency_label' => is_string($this->actual_shipping_cost_currency)
                ? CustomAsiaOrderCurrency::label($this->actual_shipping_cost_currency)
                : null,
            'actual_landed_cost_cad' => $this->actual_landed_cost_cad,
            'actual_product_fx_rate_to_cad' => $this->actual_product_fx_rate_to_cad,
            'actual_shipping_fx_rate_to_cad' => $this->actual_shipping_fx_rate_to_cad,
            'actual_fx_rate_date' => $this->actual_fx_rate_date?->toDateString(),
            'actual_receive_delay_amount' => $this->actual_receive_delay_amount,
            'actual_receive_delay_unit' => $this->actual_receive_delay_unit,
            'actual_receive_delay_unit_label' => is_string($this->actual_receive_delay_unit)
                ? CustomAsiaOrderReceiveDelayUnit::label($this->actual_receive_delay_unit)
                : null,
            'actual_receive_delay_days' => $this->actual_receive_delay_days,
            'actual_receive_delay_label' => CustomAsiaOrderReceiveDelayUnit::formatLabel(
                is_int($this->actual_receive_delay_amount) ? $this->actual_receive_delay_amount : null,
                is_string($this->actual_receive_delay_unit) ? $this->actual_receive_delay_unit : null,
            ),
            'actual_arrival_at' => $this->actual_arrival_at?->toDateString(),
            'quote_status' => $this->isQuoted() ? 'quoted' : 'pending',
            'merchandiser_price_multiplier' => $this->formatMultiplier($this->merchandiser_price_multiplier),
            'merchandiser_price_cad' => $this->merchandiser_price_cad,
            'formula_merchandiser_price_cad' => CustomAsiaOrderCustomerPricing::formulaPrice(
                is_string($this->landed_cost_cad) ? $this->landed_cost_cad : null,
                $this->formatMultiplier($this->merchandiser_price_multiplier),
                CustomAsiaOrderCustomerPricing::DEFAULT_MERCHANDISER_MULTIPLIER,
            ),
            'effective_merchandiser_multiplier' => CustomAsiaOrderCustomerPricing::effectiveMultiplier(
                is_string($this->landed_cost_cad) ? $this->landed_cost_cad : null,
                is_string($this->merchandiser_price_cad) ? $this->merchandiser_price_cad : null,
            ),
            'merchandiser_commission_cad' => CustomAsiaOrderCustomerPricing::merchandiserCommission(
                is_string($this->landed_cost_cad) ? $this->landed_cost_cad : null,
                is_string($this->merchandiser_price_cad) ? $this->merchandiser_price_cad : null,
                is_string($this->merchandiser_commission_override_cad) ? $this->merchandiser_commission_override_cad : null,
                $this->formatMultiplier($this->merchandiser_price_multiplier),
                $caps['merchandiser_commission_cap_cad'],
            ),
            'merchandiser_commission_override_cad' => $this->merchandiser_commission_override_cad,
            'our_price_multiplier' => $this->formatMultiplier($this->our_price_multiplier),
            'customer_price_cad' => $this->customer_price_cad,
            'formula_our_price_cad' => CustomAsiaOrderCustomerPricing::formulaPrice(
                is_string($this->landed_cost_cad) ? $this->landed_cost_cad : null,
                $this->formatMultiplier($this->our_price_multiplier),
                CustomAsiaOrderCustomerPricing::DEFAULT_OUR_MULTIPLIER,
            ),
            'effective_our_multiplier' => CustomAsiaOrderCustomerPricing::effectiveMultiplier(
                is_string($this->landed_cost_cad) ? $this->landed_cost_cad : null,
                is_string($this->customer_price_cad) ? $this->customer_price_cad : null,
            ),
            'our_commission_cad' => CustomAsiaOrderCustomerPricing::ourCommission(
                is_string($this->landed_cost_cad) ? $this->landed_cost_cad : null,
                is_string($this->merchandiser_price_cad) ? $this->merchandiser_price_cad : null,
                is_string($this->customer_price_cad) ? $this->customer_price_cad : null,
                is_string($this->our_commission_override_cad) ? $this->our_commission_override_cad : null,
                $this->formatMultiplier($this->merchandiser_price_multiplier),
                $this->formatMultiplier($this->our_price_multiplier),
                is_string($this->merchandiser_commission_override_cad) ? $this->merchandiser_commission_override_cad : null,
                $caps['opv_margin_cap_cad'],
                $caps['merchandiser_commission_cap_cad'],
            ),
            'our_commission_override_cad' => $this->our_commission_override_cad,
            'deposit_percent' => $this->formatPercent($this->deposit_percent),
            'deposit_amount_cad' => CustomAsiaOrderCustomerPricing::depositAmount(
                is_string($this->customer_price_cad) ? $this->customer_price_cad : null,
                $this->formatPercent($this->deposit_percent),
                is_string($this->deposit_amount_override_cad) ? $this->deposit_amount_override_cad : null,
            ),
            'deposit_amount_override_cad' => $this->deposit_amount_override_cad,
            'balance_cad' => CustomAsiaOrderCustomerPricing::balance(
                is_string($this->customer_price_cad) ? $this->customer_price_cad : null,
                $this->formatPercent($this->deposit_percent),
                is_string($this->deposit_amount_override_cad) ? $this->deposit_amount_override_cad : null,
            ),
            'pricing_status' => $this->isPriced() ? 'priced' : 'pending',
            'offer_locked_at' => $this->customer_offer_locked_at?->toIso8601String(),
            'deposit_received_at' => $this->deposit_received_at?->toIso8601String(),
            'merchandiser_ordered_at' => $this->merchandiser_ordered_at?->toIso8601String(),
            'estimated_arrival_at' => $this->estimated_arrival_at?->toDateString(),
            'product_received_at' => $this->product_received_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'competitor_prices_product_name' => $this->competitor_prices_product_name,
            'competitor_price_quotes' => $this->competitorPriceQuotesPayload(),
            'competitor_prices_fetched_at' => $this->competitor_prices_fetched_at?->toIso8601String(),
            'competitor_prices_refresh_status' => $this->competitor_prices_refresh_status,
            'competitor_prices_refresh_scope' => $this->competitor_prices_refresh_scope,
            'competitor_prices_refresh_error' => $this->competitor_prices_refresh_error,
            'competitor_prices_target_sites' => CustomAsiaOrderCompetitorPriceSites::siteOptionsForScope(
                $this->competitor_prices_refresh_scope
                    ?? ($this->competitor_prices_fetched_at !== null
                        ? CustomAsiaOrderCompetitorPriceSites::SCOPE_FULL
                        : CustomAsiaOrderCompetitorPriceSites::SCOPE_FAST),
            ),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function visualPayload(string $kind): ?array
    {
        $path = CustomAsiaOrderVisualKind::pathOn($this->resource, $kind);
        if ($path === null) {
            return null;
        }

        $filename = CustomAsiaOrderVisualKind::filenameOn($this->resource, $kind);
        $mime = CustomAsiaOrderVisualKind::mimeOn($this->resource, $kind);

        return [
            'url' => '/api/v1/custom-asia-orders/'.$this->uuid.'/visuals/'.$kind,
            'filename' => $filename,
            'mime_type' => $mime,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function competitorPriceQuotesPayload(): array
    {
        if (! is_array($this->competitor_price_quotes_json)) {
            return [];
        }

        $quotes = [];
        foreach (array_values($this->competitor_price_quotes_json) as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $siteKey = is_string($quote['site_key'] ?? null) ? $quote['site_key'] : '';
            if ($siteKey !== '' && ! isset($quote['site_url'])) {
                $quote['site_url'] = CustomAsiaOrderCompetitorPriceSites::siteUrl($siteKey);
            }

            $quotes[] = $quote;
        }

        return $quotes;
    }

    private function formatMultiplier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function formatPercent(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}

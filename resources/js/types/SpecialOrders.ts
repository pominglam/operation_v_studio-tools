import type { SpecialOrderShopifyInvoices } from './specialShopifyInvoices';
import type { SpecialOrderWorkflowStatus } from '../lib/specialOrderWorkflow';

export type SpecialOrderContactMedia = 'ig' | 'fb';

export type SpecialOrderCurrency = 'CAD' | 'CNY' | 'HKD' | 'JPY';

export type SpecialOrderShippingCostInputMode = 'amount' | 'weight';

export type SpecialOrderReceiveDelayUnit = 'days' | 'weeks' | 'months';

export const DEFAULT_SPECIAL_RECEIVE_DELAY_AMOUNT = 6;
export const DEFAULT_SPECIAL_RECEIVE_DELAY_UNIT: SpecialOrderReceiveDelayUnit = 'weeks';

export type SpecialOrderQuoteStatus = 'pending' | 'quoted';

export type SpecialOrderPricingStatus = 'pending' | 'priced';

export type SpecialOrderLifecycleStatus = 'active' | 'considering' | 'rejected' | 'all';

export const DEFAULT_MERCHANDISER_PRICE_MULTIPLIER = '1.1';

export const DEFAULT_OUR_PRICE_MULTIPLIER = '1.4';

export const DEFAULT_DEPOSIT_PERCENT = '20';

export type SpecialOrderVisual = {
    url: string;
    filename: string | null;
    mime_type: string | null;
};

export type SpecialOrderCompetitorQuote = {
    site_key: string;
    site_name: string;
    site_url: string | null;
    status: 'found' | 'not_found' | 'error' | 'pending';
    availability: 'in_stock' | 'sold_out' | null;
    currency: string;
    price: string | null;
    original_price: string | null;
    product_url: string | null;
    error_message: string | null;
};

export type SpecialOrderCompetitorPricesRefreshStatus =
    'queued' | 'running' | 'completed' | 'failed' | null;

export type SpecialOrderCompetitorTargetSite = {
    site_key: string;
    site_name: string;
    site_url: string | null;
};

export type SpecialOrder = {
    id: string;
    customer_contact_media: SpecialOrderContactMedia;
    customer_contact_media_label: string;
    customer_contact_value: string;
    product_name: string | null;
    vendor: string | null;
    customer_visual: SpecialOrderVisual | null;
    product_visual: SpecialOrderVisual | null;
    merchandiser_order_proof_visual: SpecialOrderVisual | null;
    product_cost_amount: string | null;
    product_cost_currency: SpecialOrderCurrency | null;
    product_cost_currency_label: string | null;
    shipping_cost_amount: string | null;
    shipping_cost_currency: SpecialOrderCurrency | null;
    shipping_cost_input_mode: SpecialOrderShippingCostInputMode | null;
    shipping_weight_kg: string | null;
    shipping_cost_currency_label: string | null;
    landed_cost_cad: string | null;
    product_fx_rate_to_cad: string | null;
    shipping_fx_rate_to_cad: string | null;
    fx_rate_date: string | null;
    receive_delay_amount: number | null;
    receive_delay_unit: SpecialOrderReceiveDelayUnit | null;
    receive_delay_unit_label: string | null;
    receive_delay_days: number | null;
    receive_delay_label: string | null;
    actual_product_cost_amount: string | null;
    actual_product_cost_currency: SpecialOrderCurrency | null;
    actual_product_cost_currency_label: string | null;
    actual_shipping_cost_amount: string | null;
    actual_shipping_cost_currency: SpecialOrderCurrency | null;
    actual_shipping_cost_input_mode: SpecialOrderShippingCostInputMode | null;
    actual_shipping_weight_kg: string | null;
    actual_shipping_cost_currency_label: string | null;
    actual_landed_cost_cad: string | null;
    actual_product_fx_rate_to_cad: string | null;
    actual_shipping_fx_rate_to_cad: string | null;
    actual_fx_rate_date: string | null;
    actual_receive_delay_amount: number | null;
    actual_receive_delay_unit: SpecialOrderReceiveDelayUnit | null;
    actual_receive_delay_unit_label: string | null;
    actual_receive_delay_days: number | null;
    actual_receive_delay_label: string | null;
    actual_arrival_at: string | null;
    quote_status: SpecialOrderQuoteStatus;
    merchandiser_price_multiplier: string | null;
    merchandiser_price_cad: string | null;
    formula_merchandiser_price_cad: string | null;
    effective_merchandiser_multiplier: string | null;
    merchandiser_commission_cad: string | null;
    merchandiser_commission_override_cad: string | null;
    our_price_multiplier: string | null;
    customer_price_cad: string | null;
    formula_our_price_cad: string | null;
    effective_our_multiplier: string | null;
    our_commission_cad: string | null;
    our_commission_override_cad: string | null;
    deposit_percent: string | null;
    deposit_amount_cad: string | null;
    deposit_amount_override_cad: string | null;
    balance_cad: string | null;
    pricing_status: SpecialOrderPricingStatus;
    offer_locked_at: string | null;
    customer_considering_at: string | null;
    deposit_received_at: string | null;
    balance_received_at: string | null;
    cash_received_cad: string | null;
    cash_received_at: string | null;
    shopify_invoices: SpecialOrderShopifyInvoices;
    merchandiser_ordered_at: string | null;
    estimated_arrival_at: string | null;
    product_received_at: string | null;
    rejected_at: string | null;
    competitor_prices_product_name: string | null;
    competitor_price_quotes: SpecialOrderCompetitorQuote[];
    competitor_prices_fetched_at: string | null;
    competitor_prices_refresh_status: SpecialOrderCompetitorPricesRefreshStatus;
    competitor_prices_refresh_scope: 'fast' | 'full' | null;
    competitor_prices_refresh_error: string | null;
    competitor_prices_target_sites: SpecialOrderCompetitorTargetSite[];
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type SpecialOrderFilterOptions = {
    data: {
        contact_media: Array<{ value: SpecialOrderContactMedia; label: string }>;
        currencies: Array<{ value: SpecialOrderCurrency; label: string }>;
        receive_delay_units: Array<{ value: SpecialOrderReceiveDelayUnit; label: string }>;
        quote_statuses: Array<{ value: SpecialOrderQuoteStatus; label: string }>;
        pricing_statuses: Array<{ value: SpecialOrderPricingStatus; label: string }>;
        lifecycle_statuses: Array<{ value: SpecialOrderLifecycleStatus; label: string }>;
    };
};

export type SpecialOrderWorkflowStatusCounts = Record<SpecialOrderWorkflowStatus, number>;

export type PaginatedSpecialOrders = {
    data: SpecialOrder[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    workflow_status_counts?: SpecialOrderWorkflowStatusCounts;
};

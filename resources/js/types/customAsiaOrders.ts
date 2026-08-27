export type CustomAsiaOrderContactMedia = 'ig' | 'fb';

export type CustomAsiaOrderCurrency = 'CAD' | 'CNY' | 'HKD' | 'JPY';

export type CustomAsiaOrderReceiveDelayUnit = 'days' | 'weeks' | 'months';

export const DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_AMOUNT = 6;
export const DEFAULT_CUSTOM_ASIA_RECEIVE_DELAY_UNIT: CustomAsiaOrderReceiveDelayUnit = 'weeks';

export type CustomAsiaOrderQuoteStatus = 'pending' | 'quoted';

export type CustomAsiaOrderPricingStatus = 'pending' | 'priced';

export type CustomAsiaOrderLifecycleStatus = 'active' | 'rejected' | 'all';

export const DEFAULT_MERCHANDISER_PRICE_MULTIPLIER = '1.1';

export const DEFAULT_OUR_PRICE_MULTIPLIER = '1.4';

export const DEFAULT_DEPOSIT_PERCENT = '20';

export type CustomAsiaOrderVisual = {
    url: string;
    filename: string | null;
    mime_type: string | null;
};

export type CustomAsiaOrderCompetitorQuote = {
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

export type CustomAsiaOrderCompetitorPricesRefreshStatus =
    | 'queued'
    | 'running'
    | 'completed'
    | 'failed'
    | null;

export type CustomAsiaOrderCompetitorTargetSite = {
    site_key: string;
    site_name: string;
    site_url: string | null;
};

export type CustomAsiaOrder = {
    id: string;
    customer_contact_media: CustomAsiaOrderContactMedia;
    customer_contact_media_label: string;
    customer_contact_value: string;
    product_name: string | null;
    customer_visual: CustomAsiaOrderVisual | null;
    product_visual: CustomAsiaOrderVisual | null;
    merchandiser_order_proof_visual: CustomAsiaOrderVisual | null;
    product_cost_amount: string | null;
    product_cost_currency: CustomAsiaOrderCurrency | null;
    product_cost_currency_label: string | null;
    shipping_cost_amount: string | null;
    shipping_cost_currency: CustomAsiaOrderCurrency | null;
    shipping_cost_currency_label: string | null;
    landed_cost_cad: string | null;
    product_fx_rate_to_cad: string | null;
    shipping_fx_rate_to_cad: string | null;
    fx_rate_date: string | null;
    receive_delay_amount: number | null;
    receive_delay_unit: CustomAsiaOrderReceiveDelayUnit | null;
    receive_delay_unit_label: string | null;
    receive_delay_days: number | null;
    receive_delay_label: string | null;
    actual_product_cost_amount: string | null;
    actual_product_cost_currency: CustomAsiaOrderCurrency | null;
    actual_product_cost_currency_label: string | null;
    actual_shipping_cost_amount: string | null;
    actual_shipping_cost_currency: CustomAsiaOrderCurrency | null;
    actual_shipping_cost_currency_label: string | null;
    actual_landed_cost_cad: string | null;
    actual_product_fx_rate_to_cad: string | null;
    actual_shipping_fx_rate_to_cad: string | null;
    actual_fx_rate_date: string | null;
    actual_receive_delay_amount: number | null;
    actual_receive_delay_unit: CustomAsiaOrderReceiveDelayUnit | null;
    actual_receive_delay_unit_label: string | null;
    actual_receive_delay_days: number | null;
    actual_receive_delay_label: string | null;
    actual_arrival_at: string | null;
    quote_status: CustomAsiaOrderQuoteStatus;
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
    pricing_status: CustomAsiaOrderPricingStatus;
    offer_locked_at: string | null;
    deposit_received_at: string | null;
    merchandiser_ordered_at: string | null;
    estimated_arrival_at: string | null;
    product_received_at: string | null;
    rejected_at: string | null;
    competitor_prices_product_name: string | null;
    competitor_price_quotes: CustomAsiaOrderCompetitorQuote[];
    competitor_prices_fetched_at: string | null;
    competitor_prices_refresh_status: CustomAsiaOrderCompetitorPricesRefreshStatus;
    competitor_prices_refresh_scope: 'fast' | 'full' | null;
    competitor_prices_refresh_error: string | null;
    competitor_prices_target_sites: CustomAsiaOrderCompetitorTargetSite[];
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type CustomAsiaOrderFilterOptions = {
    data: {
        contact_media: Array<{ value: CustomAsiaOrderContactMedia; label: string }>;
        currencies: Array<{ value: CustomAsiaOrderCurrency; label: string }>;
        receive_delay_units: Array<{ value: CustomAsiaOrderReceiveDelayUnit; label: string }>;
        quote_statuses: Array<{ value: CustomAsiaOrderQuoteStatus; label: string }>;
        pricing_statuses: Array<{ value: CustomAsiaOrderPricingStatus; label: string }>;
        lifecycle_statuses: Array<{ value: CustomAsiaOrderLifecycleStatus; label: string }>;
    };
};

export type PaginatedCustomAsiaOrders = {
    data: CustomAsiaOrder[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

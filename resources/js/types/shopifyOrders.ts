export type ShopifyOrderChannelOption = {
    key: string;
    label: string;
};

export type ShopifyOrderLine = {
    id: number;
    sku: string | null;
    quantity: number;
    description: string | null;
    is_store_preorder: boolean;
    sold_on: string | null;
};

export type ShopifyOrder = {
    id: number;
    name: string | null;
    customer_email: string | null;
    customer_phone: string | null;
    shopify_admin_url: string | null;
    ordered_at: string | null;
    source_name: string | null;
    channel_name: string | null;
    channel_key: string;
    channel_label: string;
    processed_by_key: string | null;
    processed_by_label: string | null;
    sales_channel_key: string;
    sales_channel_label: string;
    store_event_id: string | null;
    store_event_name: string | null;
    pos_user_id: number | null;
    financial_status: string | null;
    fulfillment_status: string | null;
    subtotal: string | null;
    cancelled_at: string | null;
    line_item_count: number | null;
    has_store_preorder: boolean;
    lines: ShopifyOrderLine[] | null;
};

export type ShopifyOrderSummary = {
    filtered_count: number;
    filtered_subtotal: string;
    last_synced_at: string | null;
    timezone: string;
    revenue_currency: string;
    channel_options: ShopifyOrderChannelOption[];
    from_date: string;
    until_date: string;
};

export type ShopifyOrderIndexResponse = {
    data: ShopifyOrder[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    summary: ShopifyOrderSummary;
};

export type ShopifyOrderShowResponse = {
    data: ShopifyOrder;
};

export type ShopifyOrderSortKey =
    | 'name'
    | 'contact'
    | 'ordered_at'
    | 'channel'
    | 'financial_status'
    | 'fulfillment_status'
    | 'subtotal'
    | 'line_count';

export type ShopifyOrderStatusFilter = 'all' | 'eligible' | 'cancelled';

export type ShopifyOrderPreorderFilter = 'all' | 'only';

export type StorePreorderRow = {
    id: string;
    status: 'open' | 'closed';
    plamod_sku: string;
    product_id: string | null;
    sku: string;
    product_name: string | null;
    deposit_percent: string;
    cap_qty: number | null;
    remaining_qty: number | null;
    selling_price_cad: string | null;
    po_cost_cad: string | null;
    deposit_amount_cad: string | null;
    window_ends_on: string | null;
    eta_date: string | null;
    opened_at: string | null;
    closed_at: string | null;
    order_count: number;
    unit_qty: number;
    image_url: string | null;
};

export type StorePreorderOrderTotals = {
    order_count: number;
    unit_qty: number;
};

export type StorePreorderBulkChanges = {
    cap_qty?: number | null;
    deposit_percent?: string;
    selling_price?: string;
    window_ends_on?: string;
};

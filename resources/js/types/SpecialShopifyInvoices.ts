export type SpecialOrderShopifyInvoicePart = {
    draft_order_gid: string;
    draft_order_legacy_id: string | null;
    draft_order_name: string | null;
    admin_url: string | null;
    invoice_url: string | null;
    sent_at: string | null;
};

export type SpecialOrderShopifyInvoices = {
    customer_gid: string | null;
    deposit: SpecialOrderShopifyInvoicePart | null;
    balance: SpecialOrderShopifyInvoicePart | null;
};

export type ShopifyCustomerSuggestion = {
    gid: string;
    display_name: string | null;
    email: string | null;
    legacy_numeric_id: string | null;
};

import { parseMoney } from '../lib/money';

export type PurchaseOrderListRow = {
    id: string;
    status: 'draft' | 'ordered' | 'shipped' | 'received' | 'on_shelves';
    shipment_method: 'air' | 'sea' | null;
    shipment_tracking_numbers: string[];
    vendor: string;
    supplier_order_id: string | null;
    vendor_currency_code: string;
    vendor_product_total: string | null;
    vendor_shipping_total: string | null;
    fx_rate_to_cad: string | null;
    ordered_date: string | null;
    shipped_date: string | null;
    estimated_arrival_date: string | null;
    received_date: string | null;
    fully_on_shelves_date: string | null;
    shipping_total: string | null;
    surcharge_total: string | null;
    product_total: string | null;
    notes: string | null;
    counts: { items: number };
    created_at: string | null;
};

export function poStatusLabel(status: PurchaseOrderListRow['status']): string {
    switch (status) {
        case 'on_shelves':
            return 'On shelves';
        case 'received':
            return 'Received';
        case 'shipped':
            return 'Shipped';
        case 'ordered':
            return 'Ordered';
        default:
            return 'Draft';
    }
}

export function poShipmentMethodLabel(method: PurchaseOrderListRow['shipment_method']): string {
    switch (method) {
        case 'air':
            return 'Air';
        case 'sea':
            return 'Sea';
        default:
            return '—';
    }
}

export function poTotal(po: PurchaseOrderListRow): string | null {
    const product = parseMoney(po.product_total);
    const shipping = parseMoney(po.shipping_total);
    const surcharge = parseMoney(po.surcharge_total);
    if (product === null && shipping === null && surcharge === null) return null;
    return ((product ?? 0) + (shipping ?? 0) + (surcharge ?? 0)).toFixed(2);
}

export function mergePurchaseOrderListRow(
    current: PurchaseOrderListRow,
    updated: Partial<PurchaseOrderListRow>,
): PurchaseOrderListRow {
    return {
        ...current,
        ...updated,
        id: current.id,
        counts: updated.counts ?? current.counts,
        shipment_tracking_numbers:
            updated.shipment_tracking_numbers ?? current.shipment_tracking_numbers ?? [],
    };
}

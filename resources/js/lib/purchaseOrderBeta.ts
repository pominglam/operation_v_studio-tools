export const PO_WORKFLOW_STEP_KEYS = [
    'import_po',
    'crawl_desc_image_price',
    'select_and_arrange_product_images',
    'set_selling_price',
    'ensure_all_products_have_barcode',
    'export_to_shopify_get_handles',
    'import_handle_only',
    'update_product_available_with_shopify_current_inventory_quantity',
    'mark_published_on_shopify',
    'mark_latest_arrival',
    'import_product_available_quantity',
] as const;

export type PoWorkflowStepKey = (typeof PO_WORKFLOW_STEP_KEYS)[number];

export type PoBetaTab = 'overview' | 'workflow' | 'lines' | 'receiving' | 'activity';

export type PoBetaLine = {
    sku: string;
    product_name: string | null;
    product_handle: string | null;
    product_barcode: string | null;
    qty_ordered: number | null;
    qty_shipped: number | null;
    qty_received: number | null;
    latest_landed_unit_cost: string | null;
    selling_price: string | null;
};

export type PoBetaSource = {
    id: string;
    vendor: string;
    supplier_order_id: string | null;
    status: 'draft' | 'ordered' | 'shipped' | 'received' | 'on_shelves';
    shipment_method: 'air' | 'sea' | null;
    vendor_currency_code: string;
    fx_rate_to_cad: string | null;
    ordered_date: string | null;
    shipped_date: string | null;
    estimated_arrival_date: string | null;
    received_date: string | null;
    fully_on_shelves_date: string | null;
    product_total: string | null;
    shipping_total: string | null;
    surcharge_total: string | null;
    notes: string | null;
    shipment_tracking_numbers: string[];
    workflow_checklist: Record<string, boolean> | null;
    items: PoBetaLine[];
};

export type PoBetaAttentionItem = {
    id: string;
    count: number;
    label: string;
    headline: string;
    priority: string;
    actionLabel: string;
    tab: PoBetaTab;
};

export type PoBetaAction = {
    id: string;
    group: 'Documents' | 'Receiving' | 'Product & pricing' | 'Shopify & catalog';
    label: string;
    description: string;
    implemented: boolean;
};

export const PO_BETA_ACTIONS: PoBetaAction[] = [
    {
        id: 'reimport',
        group: 'Documents',
        label: 'Re-import document',
        description: 'Replace current order lines',
        implemented: false,
    },
    {
        id: 'import-more',
        group: 'Documents',
        label: 'Import more',
        description: 'Append another invoice',
        implemented: false,
    },
    {
        id: 'paste-skus',
        group: 'Documents',
        label: 'Paste SKUs',
        description: 'Add draft products',
        implemented: false,
    },
    {
        id: 'export-csv',
        group: 'Documents',
        label: 'Export draft CSV',
        description: 'Vendor order file',
        implemented: false,
    },
    {
        id: 'apply-inventory-check',
        group: 'Receiving',
        label: 'Apply inventory check',
        description: 'Match counted quantities',
        implemented: false,
    },
    {
        id: 'copy-shipped',
        group: 'Receiving',
        label: 'Copy shipped → received',
        description: 'Prepare receiving',
        implemented: false,
    },
    {
        id: 'apply-received',
        group: 'Receiving',
        label: 'Apply received inventory',
        description: 'Update ERP available',
        implemented: false,
    },
    {
        id: 'bulk-update-lines',
        group: 'Receiving',
        label: 'Bulk update lines',
        description: 'Quantity, cost, vendor',
        implemented: false,
    },
    {
        id: 'set-prices',
        group: 'Product & pricing',
        label: 'Set or review prices',
        description: 'Formula and overrides',
        implemented: true,
    },
    {
        id: 'review-images',
        group: 'Product & pricing',
        label: 'Review images',
        description: 'Select PDP media',
        implemented: false,
    },
    {
        id: 'recrawl',
        group: 'Product & pricing',
        label: 'Recrawl product info',
        description: 'Description, images, prices',
        implemented: false,
    },
    {
        id: 'price-history',
        group: 'Product & pricing',
        label: 'Selling price history',
        description: 'Changes from this PO',
        implemented: true,
    },
    {
        id: 'water-decals',
        group: 'Product & pricing',
        label: 'Turn into water decals',
        description: 'Preview conversion',
        implemented: false,
    },
    {
        id: 'push-content',
        group: 'Shopify & catalog',
        label: 'Push product content',
        description: 'Create or update Shopify products',
        implemented: false,
    },
    {
        id: 'pull-handles',
        group: 'Shopify & catalog',
        label: 'Pull Shopify handles',
        description: 'Copy handles from the mirror',
        implemented: false,
    },
    {
        id: 'mark-published',
        group: 'Shopify & catalog',
        label: 'Mark published',
        description: 'ERP publication status',
        implemented: false,
    },
    {
        id: 'mark-latest',
        group: 'Shopify & catalog',
        label: 'Mark latest arrival',
        description: 'Tools excluded automatically',
        implemented: false,
    },
    {
        id: 'push-inventory',
        group: 'Shopify & catalog',
        label: 'Push inventory',
        description: 'Quantities and collection order',
        implemented: false,
    },
];

export const PO_WORKFLOW_STEP_LABELS: Record<PoWorkflowStepKey, string> = {
    import_po: 'Import PO',
    crawl_desc_image_price: 'Crawl desc, image, price',
    select_and_arrange_product_images: 'Select and arrange images',
    set_selling_price: 'Review selling prices',
    ensure_all_products_have_barcode: 'Ensure all products have barcode',
    export_to_shopify_get_handles: 'Export to Shopify for handles',
    import_handle_only: 'Pull Shopify handles',
    update_product_available_with_shopify_current_inventory_quantity:
        'Add qty received to available',
    mark_published_on_shopify: 'Mark published on Shopify',
    mark_latest_arrival: 'Mark latest arrival',
    import_product_available_quantity: 'Push inventory to Shopify',
};

export function poBetaClassicPath(id: string): string {
    return `/purchase-orders/${id}`;
}

export function poBetaPath(id: string): string {
    return `/purchase-orders/${id}/beta`;
}

export function isPurchaseOrderBetaPath(path: string): boolean {
    return /^\/purchase-orders\/[^/]+\/beta\/?$/.test(path);
}

export function parsePoBetaTab(value: unknown): PoBetaTab {
    if (
        value === 'workflow' ||
        value === 'lines' ||
        value === 'receiving' ||
        value === 'activity'
    ) {
        return value;
    }

    return 'overview';
}

export function poStatusLabel(status: PoBetaSource['status']): string {
    if (status === 'on_shelves') return 'On shelves';
    if (status === 'received') return 'Received';
    if (status === 'shipped') return 'Shipped';
    if (status === 'ordered') return 'Ordered';
    return 'Draft';
}

export function poShipmentLabel(method: PoBetaSource['shipment_method']): string {
    if (method === 'air') return 'Air';
    if (method === 'sea') return 'Sea';
    return '—';
}

function sumQty(items: PoBetaLine[], key: 'qty_ordered' | 'qty_shipped' | 'qty_received'): number {
    return items.reduce((total, item) => total + (item[key] ?? 0), 0);
}

function isBlank(value: string | null | undefined): boolean {
    return value === null || value === undefined || value.trim() === '';
}

export type PoReceivingTotals = {
    ordered: number;
    shipped: number;
    received: number;
    onShelves: number;
    unresolved: number;
};

export function poReceivingTotals(po: PoBetaSource): PoReceivingTotals {
    const ordered = sumQty(po.items, 'qty_ordered');
    const shipped = sumQty(po.items, 'qty_shipped');
    const received = sumQty(po.items, 'qty_received');
    const onShelves = po.fully_on_shelves_date ? received : 0;

    return {
        ordered,
        shipped,
        received,
        onShelves,
        unresolved: Math.max(0, ordered - received),
    };
}

export type PoWorkflowProgress = {
    completed: number;
    total: number;
    currentIndex: number;
    currentKey: PoWorkflowStepKey;
    previousKey: PoWorkflowStepKey | null;
    nextKey: PoWorkflowStepKey | null;
};

export function poWorkflowProgress(checklist: Record<string, boolean> | null): PoWorkflowProgress {
    const total = PO_WORKFLOW_STEP_KEYS.length;
    const currentIndex = PO_WORKFLOW_STEP_KEYS.findIndex((key) => checklist?.[key] !== true);
    const resolvedIndex = currentIndex === -1 ? total - 1 : currentIndex;

    return {
        completed: currentIndex === -1 ? total : currentIndex,
        total,
        currentIndex: resolvedIndex,
        currentKey: PO_WORKFLOW_STEP_KEYS[resolvedIndex],
        previousKey: resolvedIndex > 0 ? PO_WORKFLOW_STEP_KEYS[resolvedIndex - 1] : null,
        nextKey: resolvedIndex < total - 1 ? PO_WORKFLOW_STEP_KEYS[resolvedIndex + 1] : null,
    };
}

export function poAttentionItems(po: PoBetaSource): PoBetaAttentionItem[] {
    const receiving = poReceivingTotals(po);
    const missingLanded = po.items.filter((item) => isBlank(item.latest_landed_unit_cost)).length;
    const missingHandles = po.items.filter((item) => isBlank(item.product_handle)).length;
    const missingBarcodes = po.items.filter((item) => isBlank(item.product_barcode)).length;
    const items: PoBetaAttentionItem[] = [];

    if (missingLanded > 0) {
        items.push({
            id: 'landed',
            count: missingLanded,
            label:
                missingLanded === 1
                    ? 'product missing landed cost'
                    : 'products missing landed cost',
            headline: `${missingLanded} Blocking pricing`,
            priority: 'Blocking pricing',
            actionLabel: 'Resolve',
            tab: 'lines',
        });
    }

    if (missingBarcodes > 0) {
        items.push({
            id: 'barcodes',
            count: missingBarcodes,
            label: missingBarcodes === 1 ? 'product missing barcode' : 'products missing barcode',
            headline: `${missingBarcodes} Review before publish`,
            priority: 'Review before publish',
            actionLabel: 'Review',
            tab: 'lines',
        });
    }

    if (receiving.unresolved > 0) {
        items.push({
            id: 'receiving',
            count: receiving.unresolved,
            label: receiving.unresolved === 1 ? 'unit not yet received' : 'units not yet received',
            headline: `${receiving.unresolved} Receiving variance`,
            priority: 'Receiving variance',
            actionLabel: 'Inspect',
            tab: 'receiving',
        });
    }

    return items;
}

export function poShippingAndSurcharge(po: PoBetaSource): number | null {
    const shipping =
        po.shipping_total === null || po.shipping_total === '' ? null : Number(po.shipping_total);
    const surcharge =
        po.surcharge_total === null || po.surcharge_total === ''
            ? null
            : Number(po.surcharge_total);
    if (shipping === null && surcharge === null) return null;
    return (
        (Number.isFinite(shipping) ? (shipping as number) : 0) +
        (Number.isFinite(surcharge) ? (surcharge as number) : 0)
    );
}

export function poEstimatedLanded(po: PoBetaSource): number | null {
    const product =
        po.product_total === null || po.product_total === '' ? null : Number(po.product_total);
    const extras = poShippingAndSurcharge(po);
    if (product === null && extras === null) return null;
    return (Number.isFinite(product) ? (product as number) : 0) + (extras ?? 0);
}

export function poTitle(po: PoBetaSource): string {
    const date = po.received_date ?? po.ordered_date;
    if (!date) return `${po.vendor} purchase order`;
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(date) ? new Date(`${date}T12:00:00`) : new Date(date);
    if (Number.isNaN(parsed.getTime())) return `${po.vendor} purchase order`;
    const month = new Intl.DateTimeFormat('en-US', { month: 'long' }).format(parsed);
    return `${po.vendor} · ${month} shipment`;
}

export function filterPoBetaActions(query: string): PoBetaAction[] {
    const needle = query.trim().toLowerCase();
    if (needle === '') return PO_BETA_ACTIONS;
    return PO_BETA_ACTIONS.filter((action) => {
        return `${action.group} ${action.label} ${action.description}`
            .toLowerCase()
            .includes(needle);
    });
}

export function poShortRef(po: Pick<PoBetaSource, 'id' | 'supplier_order_id'>): string {
    const supplier = po.supplier_order_id?.trim();
    if (supplier) return supplier;
    return po.id.slice(0, 8).toUpperCase();
}

export type PoWorkflowTimelineStep = {
    num: string;
    label: string;
};

export type PoWorkflowTimelineFrame = {
    previous: PoWorkflowTimelineStep | null;
    current: PoWorkflowTimelineStep;
    next: PoWorkflowTimelineStep | null;
};

export function poWorkflowTimelineFrame(progress: PoWorkflowProgress): PoWorkflowTimelineFrame {
    if (progress.currentKey === 'set_selling_price') {
        return {
            previous: { num: '06', label: 'Calculate landed cost' },
            current: { num: '07', label: 'Review selling prices' },
            next: { num: '08', label: 'Pull Shopify handles' },
        };
    }

    const num = (index: number): string => String(index + 1).padStart(2, '0');

    return {
        previous: progress.previousKey
            ? {
                  num: num(progress.currentIndex - 1),
                  label: PO_WORKFLOW_STEP_LABELS[progress.previousKey],
              }
            : null,
        current: {
            num: num(progress.currentIndex),
            label: PO_WORKFLOW_STEP_LABELS[progress.currentKey],
        },
        next: progress.nextKey
            ? {
                  num: num(progress.currentIndex + 1),
                  label: PO_WORKFLOW_STEP_LABELS[progress.nextKey],
              }
            : null,
    };
}

export type PoPricePreviewSummary = {
    newCount: number;
    increaseCount: number;
    missingLandedCount: number;
};

export function poPricePreviewSummary(
    po: PoBetaSource,
    preview: {
        new_prices: unknown[];
        updates: unknown[];
        skipped_no_cost: unknown[];
    } | null,
): PoPricePreviewSummary {
    if (preview) {
        return {
            newCount: preview.new_prices.length,
            increaseCount: preview.updates.length,
            missingLandedCount: preview.skipped_no_cost.length,
        };
    }

    return {
        newCount: po.items.filter((item) => isBlank(item.selling_price)).length,
        increaseCount: 0,
        missingLandedCount: po.items.filter((item) => isBlank(item.latest_landed_unit_cost)).length,
    };
}

export function poCurrentStepSummary(
    po: PoBetaSource,
    progress: PoWorkflowProgress,
    preview: {
        new_prices: unknown[];
        updates: unknown[];
        skipped_no_cost: unknown[];
    } | null = null,
): string {
    if (progress.currentKey === 'set_selling_price') {
        const stats = poPricePreviewSummary(po, preview);
        const parts: string[] = [];

        if (stats.newCount > 0) {
            parts.push(`${stats.newCount} new`);
        }
        if (stats.increaseCount > 0) {
            parts.push(`${stats.increaseCount} increases`);
        }
        if (stats.missingLandedCount > 0) {
            parts.push(`${stats.missingLandedCount} missing landed cost`);
        }

        if (parts.length > 0) {
            return parts.join(' · ');
        }
    }

    return `${progress.completed} of ${progress.total} complete`;
}

export type PoRecentActivity = {
    text: string;
    timestamp: string | null;
};

export function poRecentActivity(
    po: PoBetaSource,
    receiving: PoReceivingTotals | null,
    historyEntry: {
        sku: string;
        previous_price: string | null;
        new_price: string | null;
        created_at: string;
    } | null,
): PoRecentActivity | null {
    if (receiving !== null && receiving.received > 0) {
        return {
            text: `Inventory check applied · ${formatCountDisplay(receiving.received)} units matched`,
            timestamp: po.received_date,
        };
    }

    if (historyEntry) {
        return {
            text: `${historyEntry.sku} · ${formatCadDisplay(historyEntry.previous_price)} → ${formatCadDisplay(historyEntry.new_price)}`,
            timestamp: historyEntry.created_at,
        };
    }

    return null;
}

export function poReceivingTrackState(totals: PoReceivingTotals): {
    seg1: '' | 'is-ok' | 'is-bad';
    seg2: '' | 'is-ok' | 'is-bad';
    seg3: '' | 'is-ok' | 'is-bad';
    receivedDotAlert: boolean;
} {
    const hasVariance = totals.unresolved > 0;

    return {
        seg1: totals.shipped > 0 ? 'is-ok' : '',
        seg2: hasVariance ? 'is-bad' : totals.received > 0 ? 'is-ok' : '',
        seg3: totals.onShelves > 0 ? 'is-ok' : '',
        receivedDotAlert: hasVariance,
    };
}

export function formatCountDisplay(value: number | null | undefined): string {
    if (value === null || value === undefined) return '0';
    return value.toLocaleString('en-US');
}

export function formatCadDisplay(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const amount = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(amount)) return '—';
    return `CAD ${amount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

export type PoReceivingBar = PoReceivingTotals & {
    receivedPct: number;
    unresolvedPct: number;
};

export function poReceivingBar(totals: PoReceivingTotals): PoReceivingBar {
    const max = Math.max(totals.ordered, 1);
    const receivedPct = Math.min(100, (totals.received / max) * 100);
    const unresolvedPct = Math.min(100 - receivedPct, (totals.unresolved / max) * 100);

    return {
        ...totals,
        receivedPct,
        unresolvedPct,
    };
}

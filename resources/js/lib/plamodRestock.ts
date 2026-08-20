export type PlamodRestockCostBreakdown = {
    product: string;
    shipping: string;
    landed: string;
};

export type PlamodRestockPreorderShipment = {
    offer_id: string | null;
    quantity: number;
    eta_date: string | null;
    eta_label: string | null;
    po_due_date: string | null;
};

export type PlamodRestockExistingRow = {
    product_uuid: string;
    sku: string;
    product_name: string;
    barcode: string | null;
    type: string | null;
    release_date: string | null;
    release_date_label: string | null;
    is_recent_release: boolean;
    available_qty: number;
    maintain_qty: number;
    not_arrived_qty: number;
    preorder_committed_qty: number;
    preorder_shipments: PlamodRestockPreorderShipment[];
    reorder_qty: number;
    reorder_qty_override: number | null;
    is_reorder_overridden: boolean;
    proposed_qty: number;
    last_landed_cost: PlamodRestockCostBreakdown | null;
    new_landed_cost: PlamodRestockCostBreakdown | null;
    line_total: PlamodRestockCostBreakdown | null;
    cost_delta_high: boolean;
    cost_delta_percent: number | null;
    plamod_pdp_url: string | null;
};

export type PlamodRestockNewRow = {
    sku: string;
    product_name: string;
    barcode: string | null;
    series: string | null;
    category: string | null;
    release_date: string | null;
    release_date_label: string | null;
    is_recent_release: boolean;
    status: 'undecided' | 'dismissed' | 'included' | 'later';
    order_qty: number | null;
    planned_maintain_qty: number | null;
    last_landed_cost: PlamodRestockCostBreakdown | null;
    new_landed_cost: PlamodRestockCostBreakdown | null;
    line_total: PlamodRestockCostBreakdown | null;
    cost_delta_high: boolean;
    cost_delta_percent: number | null;
    price_missing: boolean;
    image_url: string | null;
    plamod_pdp_url: string | null;
};

export type PlamodRestockTotals = {
    unique_products: number;
    units: number;
    product: string;
    shipping: string;
    landed: string;
    lines_with_missing_price: number;
    existing: PlamodRestockTotalsBreakdown;
    new_products: PlamodRestockTotalsBreakdown;
};

export type PlamodRestockTotalsBreakdown = {
    unique_products: number;
    units: number;
    product: string;
    shipping: string;
    landed: string;
    lines_with_missing_price: number;
};

export type PlamodRestockProposal = {
    snapshot: {
        sync_log_id: number | null;
        synced_at: string | null;
        item_count: number;
    };
    shipping_percent: number;
    exclusions: {
        excluded_series: string[];
        excluded_product_terms: string[];
    };
    existing: PlamodRestockExistingRow[];
    new_products: PlamodRestockNewRow[];
    totals: PlamodRestockTotals;
    meta: {
        existing_count: number;
        new_count: number;
        dismissed_count: number;
        undecided_new_count: number;
        included_new_count: number;
        later_new_count: number;
        new_missing_price_count: number;
    };
};

export function formatCostBreakdown(cost: PlamodRestockCostBreakdown | null): {
    landed: string;
    detail: string;
} {
    if (!cost) {
        return { landed: '—', detail: '' };
    }

    return {
        landed: `$${cost.landed}`,
        detail: `$${cost.product} + $${cost.shipping} ship`,
    };
}

export function formatProductPrice(cost: PlamodRestockCostBreakdown | null): string {
    if (!cost) {
        return '—';
    }

    return `$${cost.product}`;
}

export function formatProductCostDisplay(cost: PlamodRestockCostBreakdown | null): {
    primary: string;
    detail: string;
} {
    if (!cost) {
        return { primary: '—', detail: '' };
    }

    const shipping = Number(cost.shipping);
    return {
        primary: `$${cost.product}`,
        detail: Number.isFinite(shipping) && shipping > 0 ? `$${cost.shipping} ship est.` : '',
    };
}

export function formatLineTotal(cost: PlamodRestockCostBreakdown | null): string {
    if (!cost) {
        return '—';
    }

    return `$${cost.product}`;
}

export function releaseDateLabel(row: {
    release_date_label: string | null;
    release_date: string | null;
}): string {
    if (row.release_date_label && row.release_date_label.trim() !== '') {
        return row.release_date_label;
    }
    if (!row.release_date) {
        return '—';
    }

    const parsed = new Date(`${row.release_date}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) {
        return row.release_date;
    }

    return parsed.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
}

export const RECENT_RELEASE_TOOLTIP =
    'Released within the last 6 months — newer market releases are higher priority for stocking.';

export const REORDER_FORMULA_TOOLTIP =
    'Suggested reorder = max(0, Maintain − Available − Not arrived). Override the Order qty column to adjust what goes on the draft PO.';

export const AVAILABLE_TOOLTIP = 'Current sellable inventory in ERP (available qty).';

export const MAINTAIN_TOOLTIP =
    'Target stock level in ERP. Edit here to update maintain qty; suggested reorder recalculates on save.';

export const NOT_ARRIVED_TOOLTIP =
    'Qty ordered on purchase orders not yet fully on shelves. Received-but-not-shelved quantities remain included. Draft POs are excluded (ordered or shipped POs only).';

export const PREORDER_COMMITTED_TOOLTIP =
    'Qty already committed on PLAMOD preorders (your PLAMOD account). Hover or click the number to see ETA breakdown by offer. Distinct from Not arrived (ERP open POs).';

export const SUGGESTED_REORDER_TOOLTIP =
    'System suggestion before any order-qty override: max(0, Maintain − Available − Not arrived).';

export const NEW_COST_DELTA_TOOLTIP =
    'Amber highlight when PLAMOD product cost changed more than 3% vs last product cost.';

export const REORDER_OVERRIDE_TOOLTIP =
    'Override the suggested reorder qty. Saved per SKU for draft PO creation and future PLAMOD cart automation.';

export const NEW_LANDED_MISSING_TOOLTIP =
    'PLAMOD in-stock price missing from the last sync. Refresh from PLAMOD to backfill Price Stock via PDP enrich.';

export const PLAMOD_RESTOCK_PAGE_STATE_KEY = 'plamod_restock_page_state';

export const NEW_STATUS_TOOLTIP =
    'Status checkboxes are multi-select. Undecided: not on draft PO yet. Later: deferred until budget allows. Included: catalog-less draft PO lines. Dismissed: hidden unless explicitly selected or "Hide dismissed" is cleared.';

export const NEW_ORDER_QTY_TOOLTIP = 'Units to order on the draft PO when this SKU is included.';

export const NEW_PLANNED_MAINTAIN_TOOLTIP =
    'Target maintain qty applied to products.maintain_qty when this SKU is first created during PO import.';

export const NEW_NEW_COST_TOOLTIP =
    'PLAMOD in-stock product cost from the latest snapshot. Missing price indicates a sync/scraper gap — refresh from PLAMOD.';

export type PlamodRestockNewSortKey =
    | 'sku'
    | 'product_name'
    | 'series'
    | 'category'
    | 'release_date'
    | 'status'
    | 'order_qty'
    | 'planned_maintain_qty'
    | 'new_product_cost'
    | 'line_total';

export type PlamodRestockPageState = {
    activeTab: 'existing' | 'new';
    tableSearch: string;
    existingSearch: string;
    hideDismissed: boolean;
    onlyIncludedNew: boolean;
    filterUndecidedOnly: boolean;
    filterLaterOnly: boolean;
    filterDismissedOnly: boolean;
    filterRecentOnly: boolean;
    filterSeries: string;
    existingType: string;
    existingSortBy: PlamodRestockExistingSortKey;
    existingSortDir: 'asc' | 'desc';
    newSortBy: PlamodRestockNewSortKey;
    newSortDir: 'asc' | 'desc';
};

export function defaultPlamodRestockPageState(): PlamodRestockPageState {
    return {
        activeTab: 'existing',
        tableSearch: '',
        existingSearch: '',
        hideDismissed: true,
        onlyIncludedNew: false,
        filterUndecidedOnly: false,
        filterLaterOnly: false,
        filterDismissedOnly: false,
        filterRecentOnly: false,
        filterSeries: '',
        existingType: '',
        existingSortBy: 'product_name',
        existingSortDir: 'asc',
        newSortBy: 'release_date',
        newSortDir: 'desc',
    };
}

export function uniquePlamodRestockSeries(rows: PlamodRestockNewRow[]): string[] {
    const series = new Set<string>();
    for (const row of rows) {
        const value = row.series?.trim() ?? '';
        if (value !== '') {
            series.add(value);
        }
    }

    return [...series].sort((a, b) => a.localeCompare(b));
}

export function filterPlamodRestockNewRows(
    rows: PlamodRestockNewRow[],
    options: {
        search: string;
        undecidedOnly: boolean;
        laterOnly: boolean;
        dismissedOnly: boolean;
        includedOnly: boolean;
        recentOnly: boolean;
        series: string;
    },
): PlamodRestockNewRow[] {
    let filtered = rows;

    const selectedStatuses = new Set<PlamodRestockNewRow['status']>();
    if (options.undecidedOnly) {
        selectedStatuses.add('undecided');
    }
    if (options.laterOnly) {
        selectedStatuses.add('later');
    }
    if (options.dismissedOnly) {
        selectedStatuses.add('dismissed');
    }
    if (options.includedOnly) {
        selectedStatuses.add('included');
    }

    if (selectedStatuses.size > 0) {
        filtered = filtered.filter((row) => selectedStatuses.has(row.status));
    }

    if (options.recentOnly) {
        filtered = filtered.filter((row) => row.is_recent_release);
    }

    const seriesFilter = options.series.trim();
    if (seriesFilter !== '') {
        filtered = filtered.filter((row) => (row.series ?? '').trim() === seriesFilter);
    }

    const query = options.search.trim();
    if (query === '') {
        return filtered;
    }

    return filtered.filter((row) => plamodRestockRowMatchesSearch(row, query));
}

export function sortPlamodRestockNewRows(
    rows: PlamodRestockNewRow[],
    sortBy: PlamodRestockNewSortKey,
    sortDir: 'asc' | 'desc',
): PlamodRestockNewRow[] {
    const factor = sortDir === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        let cmp = 0;

        switch (sortBy) {
            case 'sku':
            case 'product_name':
            case 'series':
            case 'category':
            case 'release_date':
            case 'status':
                cmp = String(a[sortBy] ?? '').localeCompare(String(b[sortBy] ?? ''));
                break;
            case 'order_qty':
            case 'planned_maintain_qty':
                cmp = (a[sortBy] ?? -1) - (b[sortBy] ?? -1);
                break;
            case 'new_product_cost':
                cmp =
                    Number(a.new_landed_cost?.product ?? Number.NaN) -
                    Number(b.new_landed_cost?.product ?? Number.NaN);
                break;
            case 'line_total':
                cmp =
                    Number(a.line_total?.product ?? Number.NaN) -
                    Number(b.line_total?.product ?? Number.NaN);
                break;
            default:
                cmp = 0;
        }

        if (Number.isNaN(cmp)) {
            cmp = 0;
        }

        if (cmp === 0) {
            return a.sku.localeCompare(b.sku) * factor;
        }

        return cmp * factor;
    });
}

export function formatPlamodInstockSyncCompleteMessage(
    counts: Record<string, string | number | boolean | null | undefined>,
): string {
    const imported = Number(counts.rows_upserted ?? counts.row_count ?? 0);
    const expected = Number(counts.expected_row_count ?? 0);

    if (imported <= 0) {
        return 'PLAMOD in-stock catalog refreshed.';
    }

    if (expected > 0) {
        const pct = ((imported / expected) * 100).toFixed(1);
        return `PLAMOD in-stock catalog refreshed: ${imported} of ~${expected} SKUs (${pct}%).`;
    }

    return `PLAMOD in-stock catalog refreshed: ${imported} SKUs.`;
}

export type PlamodRestockSearchableRow = {
    sku: string;
    product_name: string;
    barcode?: string | null;
};

export function plamodRestockRowSearchHaystack(row: PlamodRestockSearchableRow): string {
    return [row.sku, row.product_name, row.barcode ?? ''].join(' ').toLowerCase();
}

export function plamodRestockRowMatchesSearch(
    row: PlamodRestockSearchableRow,
    query: string,
): boolean {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return true;
    }

    const haystack = plamodRestockRowSearchHaystack(row);
    const terms = q.split(/\s+/).filter((term) => term !== '');
    return terms.every((term) => haystack.includes(term));
}

export type PlamodRestockExistingSortKey =
    | 'sku'
    | 'product_name'
    | 'type'
    | 'release_date'
    | 'available_qty'
    | 'maintain_qty'
    | 'not_arrived_qty'
    | 'preorder_committed_qty'
    | 'reorder_qty'
    | 'proposed_qty'
    | 'last_product_cost'
    | 'new_product_cost'
    | 'line_total';

export function uniquePlamodRestockExistingTypes(rows: PlamodRestockExistingRow[]): string[] {
    return [
        ...new Set(rows.map((row) => row.type?.trim() ?? '').filter((type) => type !== '')),
    ].sort((a, b) => a.localeCompare(b));
}

export function filterPlamodRestockExistingRows(
    rows: PlamodRestockExistingRow[],
    options: { search: string; type: string },
): PlamodRestockExistingRow[] {
    const type = options.type.trim();
    return rows.filter(
        (row) =>
            (type === '' || (row.type ?? '').trim() === type) &&
            plamodRestockRowMatchesSearch(row, options.search),
    );
}

export function calculatePlamodRestockSuggestedSummary(
    rows: Array<Pick<PlamodRestockExistingRow, 'sku' | 'reorder_qty'>>,
): { uniqueProducts: number; units: number } {
    const countedSkus = new Set<string>();
    let units = 0;

    for (const row of rows) {
        if (row.reorder_qty <= 0 || countedSkus.has(row.sku)) {
            continue;
        }

        countedSkus.add(row.sku);
        units += row.reorder_qty;
    }

    return {
        uniqueProducts: countedSkus.size,
        units,
    };
}

export function calculatePlamodRestockExistingBudget(
    rows: PlamodRestockExistingRow[],
    shippingPercent: number,
): {
    skuCount: number;
    units: number;
    product: string;
    shipping: string;
    landed: string;
    linesWithMissingPrice: number;
} {
    let units = 0;
    let productCents = 0;
    let linesWithMissingPrice = 0;

    for (const row of rows) {
        units += row.proposed_qty;
        if (row.proposed_qty > 0 && row.line_total === null) {
            linesWithMissingPrice += 1;
        }
        productCents += Math.round(Number(row.line_total?.product ?? 0) * 100);
    }

    const landedCents = Math.round(productCents * (1 + shippingPercent / 100));
    const shippingCents = landedCents - productCents;

    return {
        skuCount: rows.length,
        units,
        product: (productCents / 100).toFixed(2),
        shipping: (shippingCents / 100).toFixed(2),
        landed: (landedCents / 100).toFixed(2),
        linesWithMissingPrice,
    };
}

export function sortPlamodRestockExistingRows(
    rows: PlamodRestockExistingRow[],
    sortBy: PlamodRestockExistingSortKey,
    sortDir: 'asc' | 'desc',
): PlamodRestockExistingRow[] {
    const factor = sortDir === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        let cmp = 0;

        switch (sortBy) {
            case 'sku':
            case 'product_name':
            case 'type':
            case 'release_date':
                cmp = String(a[sortBy] ?? '').localeCompare(String(b[sortBy] ?? ''));
                break;
            case 'available_qty':
            case 'maintain_qty':
            case 'not_arrived_qty':
            case 'preorder_committed_qty':
            case 'reorder_qty':
            case 'proposed_qty':
                cmp = a[sortBy] - b[sortBy];
                break;
            case 'last_product_cost':
                cmp =
                    Number(a.last_landed_cost?.product ?? Number.NaN) -
                    Number(b.last_landed_cost?.product ?? Number.NaN);
                break;
            case 'new_product_cost':
                cmp =
                    Number(a.new_landed_cost?.product ?? Number.NaN) -
                    Number(b.new_landed_cost?.product ?? Number.NaN);
                break;
            case 'line_total':
                cmp =
                    Number(a.line_total?.product ?? Number.NaN) -
                    Number(b.line_total?.product ?? Number.NaN);
                break;
            default:
                cmp = 0;
        }

        if (Number.isNaN(cmp)) {
            cmp = 0;
        }

        if (cmp === 0) {
            return a.sku.localeCompare(b.sku) * factor;
        }

        return cmp * factor;
    });
}

export function formatCostDeltaBadge(percent: number | null): string {
    if (percent === null || !Number.isFinite(percent)) {
        return '';
    }

    if (percent > 0) {
        return `↑ ${Math.abs(percent).toFixed(1)}%`;
    }

    if (percent < 0) {
        return `↓ ${Math.abs(percent).toFixed(1)}%`;
    }

    return '0%';
}

export function erpProductSearchUrl(sku: string): string {
    return `/products?search=${encodeURIComponent(sku)}`;
}

export type PlamodRestockCartVerificationStatus =
    'verified' | 'partial' | 'over_added' | 'missing' | 'add_failed' | 'already_satisfied';

export type PlamodRestockCartReportSummary = {
    requested_lines: number;
    verified: number;
    partial: number;
    over_added: number;
    missing: number;
    add_failed: number;
    already_satisfied: number;
    all_verified: boolean;
    extra_cart_lines?: number;
    order_matches_cart?: boolean;
};

export type PlamodRestockCartExtraLine = {
    sku: string;
    cart_qty: number;
};

export type PlamodRestockCartReportLine = {
    sku: string;
    product_name?: string;
    source?: string;
    requested_qty: number;
    selected_qty?: number;
    max_available?: number | null;
    cart_qty_before?: number;
    cart_qty_after?: number;
    cart_qty_added?: number;
    preorder_arrived_qty?: number;
    target_instock_qty?: number;
    add_status?: string;
    verification_status: PlamodRestockCartVerificationStatus | string;
    error_message?: string | null;
};

export type PlamodRestockCartReport = {
    cart_url?: string;
    rechecked_at?: string | null;
    verified_at?: string | null;
    scope?: 'full_order' | string;
    cart_item_badge_count?: number;
    cart_lines_detected?: number;
    preorder_arrived?: Record<string, number>;
    summary: PlamodRestockCartReportSummary;
    lines: PlamodRestockCartReportLine[];
    extra_cart_lines?: PlamodRestockCartExtraLine[];
};

export type PlamodRestockOrderVerifyStatus = {
    ok: boolean;
    report: PlamodRestockCartReport | null;
    summary: PlamodRestockCartReportSummary | null;
    all_verified: boolean | null;
    order_matches_cart: boolean | null;
    verified_at: string | null;
    line_count: number | null;
    error_summary: string | null;
    error_message?: string | null;
};

export type PlamodRestockCartRunStatus = {
    status: string;
    cart_run_id: number | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    counts: Record<string, string | number | boolean | null | undefined>;
    report: PlamodRestockCartReport | null;
    summary: PlamodRestockCartReportSummary | null;
    all_verified: boolean | null;
    error_summary: string | null;
};

export const PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY = 'plamod-restock-dismissed-cart-run';
export const PLAMOD_RESTOCK_ORDER_VERIFY_DISMISSED_AT_KEY =
    'plamod-restock-dismissed-order-verify-at';
export const PLAMOD_RESTOCK_ORDER_VERIFY_TIMEOUT_MS = 180_000;

export function formatPlamodRestockOrderVerifyHeadline(
    summary: PlamodRestockCartReportSummary | null | undefined,
): string {
    if (!summary) {
        return 'Full order verification finished.';
    }

    if (summary.order_matches_cart) {
        return `PLAMOD cart matches all ${summary.requested_lines} order line(s).`;
    }

    const verifiedTotal = summary.verified + summary.already_satisfied;
    return (
        `Full order verification incomplete: ${verifiedTotal}/${summary.requested_lines} lines match` +
        `${summary.over_added > 0 ? `, ${summary.over_added} over-added` : ''}` +
        `${summary.partial > 0 ? `, ${summary.partial} partial` : ''}` +
        `${summary.missing > 0 ? `, ${summary.missing} missing` : ''}` +
        `${(summary.extra_cart_lines ?? 0) > 0 ? `, ${summary.extra_cart_lines} extra cart line(s)` : ''}.`
    );
}

export function isOrderVerifyLineMismatch(status: string): boolean {
    return status !== 'verified' && status !== 'already_satisfied';
}

export function formatPlamodRestockCartReportHeadline(
    summary: PlamodRestockCartReportSummary | null | undefined,
): string {
    if (!summary) {
        return 'PLAMOD cart run finished.';
    }

    const verifiedTotal = summary.verified + summary.already_satisfied;
    if (summary.all_verified) {
        return `All ${summary.requested_lines} line(s) verified in PLAMOD cart.`;
    }

    return (
        `Cart verification incomplete: ${verifiedTotal}/${summary.requested_lines} verified` +
        `${summary.over_added > 0 ? `, ${summary.over_added} over-added` : ''}` +
        `${summary.partial > 0 ? `, ${summary.partial} partial` : ''}` +
        `${summary.missing > 0 ? `, ${summary.missing} missing` : ''}` +
        `${summary.add_failed > 0 ? `, ${summary.add_failed} add failed` : ''}.`
    );
}

export function plamodRestockCartVerificationLabel(status: string): string {
    switch (status) {
        case 'verified':
            return 'Verified';
        case 'partial':
            return 'Partial';
        case 'over_added':
            return 'Over-added';
        case 'missing':
            return 'Missing';
        case 'add_failed':
            return 'Add failed';
        case 'already_satisfied':
            return 'Already correct';
        default:
            return status;
    }
}

const cartReportRetryableStatuses = new Set<PlamodRestockCartVerificationStatus>([
    'missing',
    'add_failed',
    'partial',
    'over_added',
]);

export function isCartReportLineRetryable(status: string): boolean {
    return cartReportRetryableStatuses.has(status as PlamodRestockCartVerificationStatus);
}

export function collectCartReportRetryableSkus(lines: PlamodRestockCartReportLine[]): string[] {
    return lines
        .filter((line) => isCartReportLineRetryable(line.verification_status))
        .map((line) => line.sku);
}

export function formatPlamodRestockCartRetryConfirmMessage(
    skus: string[],
    _lines: PlamodRestockCartReportLine[],
): string {
    return (
        `Set ${skus.length} mismatched line(s) to their exact requested final quantity?\n\n` +
        'Missing and partial lines will be increased. Over-added lines will be lowered. PLAMOD constraints will be reported without claiming success.'
    );
}

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

export const PLAMOD_RESTOCK_SERIES_NONE = '__none__';

export function plamodRestockRowSeries(row: { series: string | null }): string {
    return row.series?.trim() ?? '';
}

export function uniquePlamodRestockSeries(rows: PlamodRestockNewRow[]): string[] {
    const series = new Set<string>();
    for (const row of rows) {
        const value = plamodRestockRowSeries(row);
        if (value !== '') {
            series.add(value);
        }
    }

    return [...series].sort((a, b) => a.localeCompare(b));
}

export function hasPlamodRestockRowsWithoutSeries(rows: PlamodRestockNewRow[]): boolean {
    return rows.some((row) => plamodRestockRowSeries(row) === '');
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
        hideDismissed?: boolean;
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
    } else if (options.hideDismissed) {
        filtered = filtered.filter((row) => row.status !== 'dismissed');
    }

    if (options.recentOnly) {
        filtered = filtered.filter((row) => row.is_recent_release);
    }

    const seriesFilter = options.series.trim();
    if (seriesFilter === PLAMOD_RESTOCK_SERIES_NONE) {
        filtered = filtered.filter((row) => plamodRestockRowSeries(row) === '');
    } else if (seriesFilter !== '') {
        filtered = filtered.filter((row) => plamodRestockRowSeries(row) === seriesFilter);
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

export type PlamodInstockFailedFilter = {
    name: string;
    tab: string;
    category_id: string | null;
    expected: number;
    rows: number;
    error: string | null;
};

export function parsePlamodInstockFailedFilters(raw: unknown): PlamodInstockFailedFilter[] {
    if (!Array.isArray(raw)) {
        return [];
    }

    const parsed: PlamodInstockFailedFilter[] = [];
    for (const item of raw) {
        if (item === null || typeof item !== 'object') {
            continue;
        }
        const row = item as Record<string, unknown>;
        const name = String(row.name ?? '').trim();
        if (name === '') {
            continue;
        }
        const error = String(row.error ?? '').trim();
        parsed.push({
            name,
            tab: String(row.tab ?? 'BRAND').trim() || 'BRAND',
            category_id: String(row.category_id ?? '').trim() || null,
            expected: Number(row.expected ?? 0) || 0,
            rows: Number(row.rows ?? 0) || 0,
            error: error !== '' ? error : null,
        });
    }

    return parsed;
}

export function plamodInstockFailedFilterKey(filter: PlamodInstockFailedFilter): string {
    return `${filter.tab}\t${filter.name}`;
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
export const PLAMOD_RESTOCK_SYNC_QUEUE_TIMEOUT_MS = 15_000;

export function plamodRestockRequestErrorMessage(error: unknown, fallback: string): string {
    if (error instanceof Error && error.message.toLowerCase().includes('timeout')) {
        return 'The request timed out. PLAMOD refresh keeps running in the background and usually takes several minutes — wait for the progress button to finish, then reload if the snapshot is still missing.';
    }

    if (error instanceof Error && error.message.trim() !== '') {
        return error.message;
    }

    return fallback;
}

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

export const PLAMOD_RESTOCK_NEW_PAGE_SIZE = 50;

export type PlamodRestockDecisionPayload = {
    sku: string;
    status: PlamodRestockNewRow['status'];
    order_qty: number | null;
    planned_maintain_qty: number | null;
};

export function plamodRestockNewLineTotal(
    row: PlamodRestockNewRow,
    orderQty: number | null,
): PlamodRestockCostBreakdown | null {
    if (orderQty === null || orderQty <= 0 || row.new_landed_cost === null) {
        return null;
    }

    const product = (orderQty * Number(row.new_landed_cost.product)).toFixed(2);

    return {
        product,
        shipping: '0.00',
        landed: product,
    };
}

export function recomputePlamodRestockTotals(
    existing: PlamodRestockExistingRow[],
    newProducts: PlamodRestockNewRow[],
    shippingPercent: number,
): PlamodRestockTotals {
    const existingTotals = plamodRestockTotalsBreakdown(existing, 'proposed_qty', shippingPercent);
    const includedNew = newProducts.filter((row) => row.status === 'included');
    const newTotals = plamodRestockTotalsBreakdown(includedNew, 'order_qty', shippingPercent);

    return {
        unique_products: existingTotals.unique_products + newTotals.unique_products,
        units: existingTotals.units + newTotals.units,
        product: sumMoney(existingTotals.product, newTotals.product),
        shipping: sumMoney(existingTotals.shipping, newTotals.shipping),
        landed: sumMoney(existingTotals.landed, newTotals.landed),
        lines_with_missing_price:
            existingTotals.lines_with_missing_price + newTotals.lines_with_missing_price,
        existing: existingTotals,
        new_products: newTotals,
    };
}

function plamodRestockTotalsBreakdown(
    lines: Array<PlamodRestockExistingRow | PlamodRestockNewRow>,
    quantityKey: 'proposed_qty' | 'order_qty',
    shippingPercent: number,
): PlamodRestockTotalsBreakdown {
    let units = 0;
    let productTotal = 0;
    let missingPriceLines = 0;
    const uniqueSkus = new Set<string>();

    lines.forEach((line, index) => {
        const qty =
            quantityKey === 'proposed_qty'
                ? (line as PlamodRestockExistingRow).proposed_qty
                : ((line as PlamodRestockNewRow).order_qty ?? 0);
        if (qty <= 0) {
            return;
        }

        uniqueSkus.add(line.sku !== '' ? line.sku : `__line_${index}`);
        units += qty;
        if (line.new_landed_cost === null) {
            missingPriceLines += 1;
            return;
        }

        productTotal += qty * Number(line.new_landed_cost.product);
    });

    const shippingTotal = Math.round(productTotal * (shippingPercent / 100) * 100) / 100;

    return {
        unique_products: uniqueSkus.size,
        units,
        product: productTotal.toFixed(2),
        shipping: shippingTotal.toFixed(2),
        landed: (productTotal + shippingTotal).toFixed(2),
        lines_with_missing_price: missingPriceLines,
    };
}

function sumMoney(first: string, second: string): string {
    return (Number(first) + Number(second)).toFixed(2);
}

export function recountPlamodRestockNewMeta(
    newProducts: PlamodRestockNewRow[],
    existingCount: number,
): PlamodRestockProposal['meta'] {
    let dismissed_count = 0;
    let undecided_new_count = 0;
    let included_new_count = 0;
    let later_new_count = 0;
    let new_missing_price_count = 0;

    for (const row of newProducts) {
        if (row.price_missing) {
            new_missing_price_count += 1;
        }
        if (row.status === 'dismissed') {
            dismissed_count += 1;
        } else if (row.status === 'included') {
            included_new_count += 1;
        } else if (row.status === 'later') {
            later_new_count += 1;
        } else {
            undecided_new_count += 1;
        }
    }

    return {
        existing_count: existingCount,
        new_count: newProducts.length,
        dismissed_count,
        undecided_new_count,
        included_new_count,
        later_new_count,
        new_missing_price_count,
    };
}

export function applyPlamodRestockNewDecision(
    proposal: PlamodRestockProposal,
    decision: PlamodRestockDecisionPayload,
): PlamodRestockProposal {
    return applyPlamodRestockNewDecisions(proposal, [decision]);
}

export function applyPlamodRestockNewDecisions(
    proposal: PlamodRestockProposal,
    decisions: PlamodRestockDecisionPayload[],
): PlamodRestockProposal {
    if (decisions.length === 0) {
        return proposal;
    }

    const bySku = new Map(decisions.map((decision) => [decision.sku, decision]));
    const new_products = proposal.new_products.map((row) => {
        const decision = bySku.get(row.sku);
        if (decision === undefined) {
            return row;
        }

        return {
            ...row,
            status: decision.status,
            order_qty: decision.order_qty,
            planned_maintain_qty: decision.planned_maintain_qty,
            line_total: plamodRestockNewLineTotal(row, decision.order_qty),
        };
    });

    return {
        ...proposal,
        new_products,
        totals: recomputePlamodRestockTotals(
            proposal.existing,
            new_products,
            proposal.shipping_percent,
        ),
        meta: recountPlamodRestockNewMeta(new_products, proposal.existing.length),
    };
}

export function mergePlamodRestockProposalSections(
    existingPart: PlamodRestockProposal,
    newPart: PlamodRestockProposal,
): PlamodRestockProposal {
    return {
        snapshot: newPart.snapshot,
        shipping_percent: newPart.shipping_percent,
        exclusions: newPart.exclusions,
        existing: existingPart.existing,
        new_products: newPart.new_products,
        totals: recomputePlamodRestockTotals(
            existingPart.existing,
            newPart.new_products,
            newPart.shipping_percent,
        ),
        meta: recountPlamodRestockNewMeta(newPart.new_products, existingPart.existing.length),
    };
}

export function paginatePlamodRestockRows<T>(rows: T[], page: number, pageSize: number): T[] {
    const safePage = Math.max(1, page);
    const start = (safePage - 1) * pageSize;

    return rows.slice(start, start + pageSize);
}

export function applyInclusiveSkuRangeSelection(
    selected: Record<string, boolean>,
    orderedSkus: string[],
    fromSku: string,
    toSku: string,
): Record<string, boolean> {
    const from = orderedSkus.indexOf(fromSku);
    const to = orderedSkus.indexOf(toSku);
    if (from < 0 || to < 0) {
        return { ...selected, [toSku]: true };
    }

    const lo = Math.min(from, to);
    const hi = Math.max(from, to);
    const next = { ...selected };
    for (let index = lo; index <= hi; index += 1) {
        next[orderedSkus[index]] = true;
    }

    return next;
}

export function applySkuCheckboxChange(input: {
    selected: Record<string, boolean>;
    orderedSkus: string[];
    sku: string;
    checked: boolean;
    shiftKey: boolean;
    anchorSku: string | null;
}): { selected: Record<string, boolean>; anchorSku: string | null } {
    if (input.shiftKey && input.anchorSku !== null) {
        return {
            selected: applyInclusiveSkuRangeSelection(
                input.selected,
                input.orderedSkus,
                input.anchorSku,
                input.sku,
            ),
            anchorSku: input.anchorSku,
        };
    }

    return {
        selected: { ...input.selected, [input.sku]: input.checked },
        anchorSku: input.sku,
    };
}

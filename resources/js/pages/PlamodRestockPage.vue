<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ColumnHeaderHelp from '../components/ColumnHeaderHelp.vue';
import PlamodRestockImageOverlay from '../components/PlamodRestockImageOverlay.vue';
import PlamodRestockPreorderQtyCell from '../components/PlamodRestockPreorderQtyCell.vue';
import { api } from '../lib/api';
import { formatLocalDateTime } from '../lib/datetime';
import { loadPageState, savePageState } from '../lib/pageState';
import { buildPlamodPdpUrl } from '../lib/pdpSources';
import {
    AVAILABLE_TOOLTIP,
    calculatePlamodRestockExistingBudget,
    calculatePlamodRestockSuggestedSummary,
    defaultPlamodRestockPageState,
    erpProductSearchUrl,
    filterPlamodRestockExistingRows,
    filterPlamodRestockNewRows,
    formatCostDeltaBadge,
    formatLineTotal,
    formatProductPrice,
    formatPlamodInstockSyncCompleteMessage,
    formatPlamodRestockCartReportHeadline,
    formatPlamodRestockCartRetryConfirmMessage,
    formatPlamodRestockOrderVerifyHeadline,
    collectCartReportRetryableSkus,
    isOrderVerifyLineMismatch,
    MAINTAIN_TOOLTIP,
    NEW_COST_DELTA_TOOLTIP,
    NEW_LANDED_MISSING_TOOLTIP,
    NEW_NEW_COST_TOOLTIP,
    NEW_ORDER_QTY_TOOLTIP,
    NEW_PLANNED_MAINTAIN_TOOLTIP,
    NEW_STATUS_TOOLTIP,
    NOT_ARRIVED_TOOLTIP,
    PREORDER_COMMITTED_TOOLTIP,
    PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY,
    PLAMOD_RESTOCK_ORDER_VERIFY_DISMISSED_AT_KEY,
    PLAMOD_RESTOCK_ORDER_VERIFY_TIMEOUT_MS,
    PLAMOD_RESTOCK_PAGE_STATE_KEY,
    plamodRestockCartVerificationLabel,
    RECENT_RELEASE_TOOLTIP,
    REORDER_OVERRIDE_TOOLTIP,
    releaseDateLabel,
    sortPlamodRestockExistingRows,
    sortPlamodRestockNewRows,
    SUGGESTED_REORDER_TOOLTIP,
    uniquePlamodRestockExistingTypes,
    uniquePlamodRestockSeries,
    type PlamodRestockExistingSortKey,
    type PlamodRestockNewRow,
    type PlamodRestockNewSortKey,
    type PlamodRestockPageState,
    type PlamodRestockProposal,
    type PlamodRestockCartReport,
    type PlamodRestockCartReportLine,
    type PlamodRestockOrderVerifyStatus,
} from '../lib/plamodRestock';

const loading = ref(false);
const error = ref<string | null>(null);
const proposal = ref<PlamodRestockProposal | null>(null);
const shippingPercentInput = ref('5');
const savingShipping = ref(false);
const hideDismissed = ref(true);
const onlyIncludedNew = ref(false);
const syncing = ref(false);
const syncStatus = ref<string>('never');
const syncCounts = ref<Record<string, string | number | boolean>>({});
const syncMessage = ref<string | null>(null);
const draftMessage = ref<string | null>(null);
const draftError = ref<string | null>(null);
const creatingDraft = ref(false);
const cartRunning = ref(false);
const cartRechecking = ref(false);
const cartRunStatus = ref<string>('never');
const cartRunId = ref<number | null>(null);
const cartRunCounts = ref<Record<string, string | number | boolean>>({});
const cartReport = ref<PlamodRestockCartReport | null>(null);
const cartRunMessage = ref<string | null>(null);
const cartRunError = ref<string | null>(null);
const cartRunFailed = ref(false);
const orderVerifying = ref(false);
const orderVerifyReport = ref<PlamodRestockCartReport | null>(null);
const orderVerifyMessage = ref<string | null>(null);
const orderVerifyError = ref<string | null>(null);
const orderVerifyFailed = ref(false);
const orderVerifyMismatchesOnly = ref(true);

const includeDraft = ref<{ sku: string; orderQty: string; maintainQty: string } | null>(null);
const includeSaving = ref(false);
const reorderDrafts = ref<Record<string, string>>({});
const reorderSavingSku = ref<string | null>(null);
const maintainDrafts = ref<Record<string, string>>({});
const maintainSavingUuid = ref<string | null>(null);
const syncFailed = ref(false);
const activeTab = ref<'existing' | 'new'>('existing');
const tableSearch = ref('');
const existingSearch = ref('');
const existingType = ref('');
const existingSortBy = ref<PlamodRestockExistingSortKey>('product_name');
const existingSortDir = ref<'asc' | 'desc'>('asc');
const filterUndecidedOnly = ref(false);
const filterLaterOnly = ref(false);
const filterDismissedOnly = ref(false);
const filterRecentOnly = ref(false);
const filterSeries = ref('');
const exclusionProductTermDraft = ref('');
const exclusionSeriesDraft = ref('');
const savingExclusions = ref(false);
const newSortBy = ref<PlamodRestockNewSortKey>('release_date');
const newSortDir = ref<'asc' | 'desc'>('desc');
const newOrderDrafts = ref<Record<string, string>>({});
const newMaintainDrafts = ref<Record<string, string>>({});
const newDecisionSavingSku = ref<string | null>(null);
const selectedNewSkus = ref<Record<string, boolean>>({});
const selectedCartSkus = ref<Record<string, boolean>>({});
const bulkIncludeDraft = ref<{ skus: string[]; orderQty: string; maintainQty: string } | null>(
    null,
);
const bulkSaving = ref(false);
const imageOverlay = ref<{ imageUrl: string; alt: string } | null>(null);

let syncPollTimer: ReturnType<typeof setInterval> | null = null;
let cartPollTimer: ReturnType<typeof setInterval> | null = null;
let proposalRequestSequence = 0;

function applyPageState(): void {
    const saved = loadPageState<PlamodRestockPageState>(PLAMOD_RESTOCK_PAGE_STATE_KEY);
    const state = saved
        ? { ...defaultPlamodRestockPageState(), ...saved }
        : defaultPlamodRestockPageState();
    activeTab.value = state.activeTab ?? 'existing';
    tableSearch.value = state.tableSearch;
    existingSearch.value = state.existingSearch ?? '';
    existingType.value = state.existingType ?? '';
    hideDismissed.value = state.hideDismissed;
    onlyIncludedNew.value = state.onlyIncludedNew;
    filterUndecidedOnly.value = state.filterUndecidedOnly;
    filterLaterOnly.value = state.filterLaterOnly ?? false;
    filterDismissedOnly.value = state.filterDismissedOnly ?? false;
    filterRecentOnly.value = state.filterRecentOnly;
    filterSeries.value = state.filterSeries;
    existingSortBy.value = state.existingSortBy;
    existingSortDir.value = state.existingSortDir;
    newSortBy.value = state.newSortBy;
    newSortDir.value = state.newSortDir;
}

function persistPageState(): void {
    savePageState<PlamodRestockPageState>(PLAMOD_RESTOCK_PAGE_STATE_KEY, {
        activeTab: activeTab.value,
        tableSearch: tableSearch.value,
        existingSearch: existingSearch.value,
        existingType: existingType.value,
        hideDismissed: hideDismissed.value,
        onlyIncludedNew: onlyIncludedNew.value,
        filterUndecidedOnly: filterUndecidedOnly.value,
        filterLaterOnly: filterLaterOnly.value,
        filterDismissedOnly: filterDismissedOnly.value,
        filterRecentOnly: filterRecentOnly.value,
        filterSeries: filterSeries.value,
        existingSortBy: existingSortBy.value,
        existingSortDir: existingSortDir.value,
        newSortBy: newSortBy.value,
        newSortDir: newSortDir.value,
    });
}

function syncNewProductDrafts(): void {
    const nextOrder: Record<string, string> = {};
    const nextMaintain: Record<string, string> = {};
    for (const row of proposal.value?.new_products ?? []) {
        if (row.status === 'included') {
            nextOrder[row.sku] = row.order_qty != null ? String(row.order_qty) : '';
            nextMaintain[row.sku] =
                row.planned_maintain_qty != null ? String(row.planned_maintain_qty) : '';
        }
    }
    newOrderDrafts.value = nextOrder;
    newMaintainDrafts.value = nextMaintain;
}

function syncReorderDrafts(): void {
    const nextReorder: Record<string, string> = {};
    const nextMaintain: Record<string, string> = {};
    for (const row of proposal.value?.existing ?? []) {
        nextReorder[row.sku] = String(row.proposed_qty);
        nextMaintain[row.product_uuid] = String(row.maintain_qty);
    }
    reorderDrafts.value = nextReorder;
    maintainDrafts.value = nextMaintain;
}

const requestHideDismissed = computed<boolean>(
    () => hideDismissed.value && !filterDismissedOnly.value,
);
const requestOnlyIncludedNew = computed<boolean>(
    () =>
        onlyIncludedNew.value &&
        !filterUndecidedOnly.value &&
        !filterLaterOnly.value &&
        !filterDismissedOnly.value,
);

async function loadProposal(options: { silent?: boolean } = {}): Promise<void> {
    const requestSequence = ++proposalRequestSequence;
    const showLoading = !options.silent && proposal.value === null;
    if (showLoading) {
        loading.value = true;
    }
    error.value = null;
    try {
        const res = await api.get<{ ok: boolean; data: PlamodRestockProposal }>(
            '/api/v1/plamod/restock/proposal',
            {
                params: {
                    hide_dismissed: requestHideDismissed.value ? 1 : 0,
                    only_included_new: requestOnlyIncludedNew.value ? 1 : 0,
                },
            },
        );
        if (requestSequence !== proposalRequestSequence) {
            return;
        }
        proposal.value = res.data.data;
        if (proposal.value) {
            shippingPercentInput.value = String(proposal.value.shipping_percent);
            syncReorderDrafts();
            syncNewProductDrafts();
        }
    } catch (e: unknown) {
        if (requestSequence !== proposalRequestSequence) {
            return;
        }
        const msg = e instanceof Error ? e.message : 'Failed to load restock proposal.';
        error.value = msg;
    } finally {
        if (requestSequence === proposalRequestSequence) {
            loading.value = false;
        }
    }
}

async function saveShippingPercent(): Promise<void> {
    savingShipping.value = true;
    error.value = null;
    try {
        const value = Number(shippingPercentInput.value);
        await api.put('/api/v1/plamod/restock/settings', {
            shipping_percent: Number.isFinite(value) ? value : 5,
        });
        await loadProposal();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to save shipping percent.';
    } finally {
        savingShipping.value = false;
    }
}

async function saveExclusions(series: string[], productTerms: string[]): Promise<void> {
    savingExclusions.value = true;
    error.value = null;
    try {
        await api.put('/api/v1/plamod/restock/settings', {
            shipping_percent: proposal.value?.shipping_percent ?? 5,
            excluded_series: series,
            excluded_product_terms: productTerms,
        });
        await loadProposal();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to save automatic exclusions.';
    } finally {
        savingExclusions.value = false;
    }
}

async function addExcludedSeries(): Promise<void> {
    const series = exclusionSeriesDraft.value.trim();
    if (series === '') return;
    exclusionSeriesDraft.value = '';
    await saveExclusions([...excludedSeries.value, series], excludedProductTerms.value);
}

async function addExcludedProductTerm(): Promise<void> {
    const term = exclusionProductTermDraft.value.trim();
    if (term.length < 2) return;
    exclusionProductTermDraft.value = '';
    await saveExclusions(excludedSeries.value, [...excludedProductTerms.value, term]);
}

async function removeExcludedSeries(series: string): Promise<void> {
    if (!window.confirm(`Stop automatically hiding the series "${series}"?`)) return;
    await saveExclusions(
        excludedSeries.value.filter((value) => value !== series),
        excludedProductTerms.value,
    );
}

async function removeExcludedProductTerm(term: string): Promise<void> {
    if (!window.confirm(`Stop automatically hiding product names containing "${term}"?`)) return;
    await saveExclusions(
        excludedSeries.value,
        excludedProductTerms.value.filter((value) => value !== term),
    );
}

async function pollSyncStatus(): Promise<void> {
    const res = await api.get<{
        data: {
            status: string;
            error_summary?: string | null;
            counts?: Record<string, string | number | boolean>;
        };
    }>('/api/v1/plamod/restock/sync-status');
    syncStatus.value = res.data.data.status;
    syncCounts.value = res.data.data.counts ?? {};
    if (syncStatus.value === 'completed') {
        syncFailed.value = false;
        syncMessage.value = formatPlamodInstockSyncCompleteMessage(syncCounts.value);
        syncing.value = false;
        stopSyncPoll();
        await loadProposal();
    } else if (syncStatus.value === 'failed') {
        syncFailed.value = true;
        syncMessage.value = res.data.data.error_summary ?? 'PLAMOD refresh failed.';
        syncing.value = false;
        stopSyncPoll();
    } else if (syncStatus.value === 'queued' || syncStatus.value === 'running') {
        syncing.value = true;
        syncFailed.value = false;
    } else {
        syncFailed.value = false;
    }
}

function stopSyncPoll(): void {
    if (syncPollTimer !== null) {
        clearInterval(syncPollTimer);
        syncPollTimer = null;
    }
}

async function pollCartRunStatus(): Promise<void> {
    const res = await api.get<{
        data: {
            status: string;
            cart_run_id?: number | null;
            error_summary?: string | null;
            counts?: Record<string, string | number | boolean>;
            report?: PlamodRestockCartReport | null;
            summary?: PlamodRestockCartReport['summary'] | null;
            all_verified?: boolean | null;
        };
    }>('/api/v1/plamod/restock/cart-run-status');

    const data = res.data?.data;
    if (!data || typeof data.status !== 'string') {
        return;
    }

    cartRunStatus.value = data.status;
    cartRunId.value = data.cart_run_id ?? null;
    cartRunCounts.value = data.counts ?? {};
    const dismissedRunId = window.localStorage.getItem(PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY);
    const reportIsDismissed =
        cartRunId.value !== null && dismissedRunId === String(cartRunId.value);
    if (!reportIsDismissed) {
        cartReport.value = data.report ?? cartReport.value;
    }

    if (cartRunStatus.value === 'completed') {
        cartRunning.value = false;
        if (!reportIsDismissed) {
            cartReport.value =
                data.report ??
                (data.counts?.report as PlamodRestockCartReport | undefined) ??
                cartReport.value;
            cartRunFailed.value =
                (data.all_verified ?? cartReport.value?.summary.all_verified ?? false) !== true;
            cartRunMessage.value = formatPlamodRestockCartReportHeadline(
                data.summary ?? cartReport.value?.summary ?? null,
            );
        }
        cartRunError.value = null;
        stopCartPoll();
    } else if (cartRunStatus.value === 'failed') {
        cartRunning.value = false;
        cartRunFailed.value = true;
        cartRunError.value = data.error_summary ?? 'PLAMOD cart automation failed.';
        cartRunMessage.value = null;
        stopCartPoll();
    } else if (cartRunStatus.value === 'queued' || cartRunStatus.value === 'running') {
        cartRunning.value = true;
        cartRunFailed.value = false;
        startCartPoll();
    } else {
        cartRunFailed.value = false;
    }
}

function startCartPoll(): void {
    if (cartPollTimer !== null) {
        return;
    }
    cartPollTimer = setInterval(() => {
        void pollCartRunStatus();
    }, 3000);
}

function stopCartPoll(): void {
    if (cartPollTimer !== null) {
        clearInterval(cartPollTimer);
        cartPollTimer = null;
    }
}

async function queuePlamodCartRun(skus: string[], confirmMessage: string): Promise<void> {
    if (skus.length === 0) {
        return;
    }

    const ok = window.confirm(confirmMessage);
    if (!ok) {
        return;
    }

    cartRunning.value = true;
    cartRunMessage.value = null;
    cartRunError.value = null;
    cartReport.value = null;
    window.localStorage.removeItem(PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY);
    error.value = null;

    try {
        await api.post('/api/v1/plamod/restock/cart-run', { skus });
        cartRunStatus.value = 'queued';
        stopCartPoll();
        startCartPoll();
        await pollCartRunStatus();
    } catch (e: unknown) {
        cartRunning.value = false;
        error.value = e instanceof Error ? e.message : 'Failed to queue PLAMOD cart automation.';
    }
}

function dismissCartReport(): void {
    if (cartRunId.value !== null) {
        window.localStorage.setItem(PLAMOD_RESTOCK_CART_DISMISSED_RUN_KEY, String(cartRunId.value));
    }
    cartReport.value = null;
    cartRunMessage.value = null;
    cartRunError.value = null;
    cartRunFailed.value = false;
}

async function addProposalToPlamodCart(): Promise<void> {
    const skus = selectedCartSkuList.value;
    if (skus.length === 0) {
        error.value =
            'Select one or more rows using the Cart checkboxes before adding to PLAMOD cart.';
        return;
    }

    const units = selectedCartUnits.value;
    await queuePlamodCartRun(
        skus,
        `Set ${skus.length} selected line(s) (${units} requested unit(s)) in your PLAMOD cart?\n\n` +
            'Each selected SKU will be set to its exact requested final quantity. Existing over-added quantities will be lowered when PLAMOD allows it.',
    );
}

async function retryFailedCartLines(): Promise<void> {
    const skus = cartRetryableSkuList.value;
    if (skus.length === 0) {
        return;
    }

    await queuePlamodCartRun(
        skus,
        formatPlamodRestockCartRetryConfirmMessage(skus, cartReportLines.value),
    );
}

async function fixFullOrderCartMismatches(): Promise<void> {
    const skus = orderVerifyRepairableSkuList.value;
    if (skus.length === 0) {
        return;
    }

    await queuePlamodCartRun(
        skus,
        formatPlamodRestockCartRetryConfirmMessage(skus, orderVerifyReport.value?.lines ?? []),
    );
}

async function recheckPlamodCart(): Promise<void> {
    if (cartReportLines.value.length === 0) {
        return;
    }

    cartRechecking.value = true;
    cartRunError.value = null;
    error.value = null;

    try {
        const res = await api.post<{
            data: {
                ok: boolean;
                report?: PlamodRestockCartReport | null;
                summary?: PlamodRestockCartReport['summary'] | null;
                all_verified?: boolean | null;
                error_summary?: string | null;
                error_message?: string | null;
            };
        }>('/api/v1/plamod/restock/cart-run-recheck');

        cartReport.value = res.data.data.report ?? cartReport.value;
        cartRunFailed.value =
            (res.data.data.all_verified ?? cartReport.value?.summary.all_verified ?? false) !==
            true;
        cartRunMessage.value = formatPlamodRestockCartReportHeadline(
            res.data.data.summary ?? cartReport.value?.summary ?? null,
        );
        cartRunError.value = null;
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to recheck PLAMOD cart.';
    } finally {
        cartRechecking.value = false;
    }
}

function applyOrderVerifySnapshot(data: PlamodRestockOrderVerifyStatus): void {
    const dismissedAt = window.localStorage.getItem(PLAMOD_RESTOCK_ORDER_VERIFY_DISMISSED_AT_KEY);
    const verifiedAt = data.verified_at ?? data.report?.verified_at ?? null;
    const reportIsDismissed =
        verifiedAt !== null && dismissedAt !== null && dismissedAt === verifiedAt;

    if (!reportIsDismissed && data.report) {
        orderVerifyReport.value = data.report;
        orderVerifyMessage.value = formatPlamodRestockOrderVerifyHeadline(
            data.summary ?? data.report.summary ?? null,
        );
        orderVerifyFailed.value =
            (data.order_matches_cart ?? data.report.summary.order_matches_cart ?? false) !== true;
        orderVerifyError.value = null;
    }
}

async function loadOrderVerifyStatus(): Promise<void> {
    try {
        const res = await api.get<{ data: PlamodRestockOrderVerifyStatus }>(
            '/api/v1/plamod/restock/order-verify',
        );
        applyOrderVerifySnapshot(res.data.data);
    } catch {
        // Non-blocking page load.
    }
}

async function verifyFullOrderAgainstPlamodCart(): Promise<void> {
    if (orderEligibleLineCount.value === 0) {
        return;
    }

    orderVerifying.value = true;
    orderVerifyError.value = null;
    error.value = null;

    try {
        const res = await api.post<{ data: PlamodRestockOrderVerifyStatus }>(
            '/api/v1/plamod/restock/order-verify',
            {},
            { timeout: PLAMOD_RESTOCK_ORDER_VERIFY_TIMEOUT_MS },
        );
        window.localStorage.removeItem(PLAMOD_RESTOCK_ORDER_VERIFY_DISMISSED_AT_KEY);
        applyOrderVerifySnapshot(res.data.data);
        orderVerifyReport.value = res.data.data.report ?? orderVerifyReport.value;
    } catch (e: unknown) {
        error.value =
            e instanceof Error ? e.message : 'Failed to verify full order against PLAMOD cart.';
    } finally {
        orderVerifying.value = false;
    }
}

function dismissOrderVerifyReport(): void {
    const verifiedAt = orderVerifyReport.value?.verified_at ?? null;
    if (verifiedAt !== null) {
        window.localStorage.setItem(PLAMOD_RESTOCK_ORDER_VERIFY_DISMISSED_AT_KEY, verifiedAt);
    }
    orderVerifyMessage.value = null;
    orderVerifyError.value = null;
    orderVerifyFailed.value = false;
}

function cartVerificationClass(status: string): string {
    switch (status) {
        case 'verified':
        case 'already_satisfied':
            return 'bg-emerald-100 text-emerald-800';
        case 'partial':
        case 'over_added':
            return 'bg-amber-100 text-amber-900';
        default:
            return 'bg-rose-100 text-rose-800';
    }
}

function cartReportLineLabel(line: PlamodRestockCartReportLine): string {
    const target = line.target_instock_qty ?? line.requested_qty;
    const after = line.cart_qty_after ?? 0;
    return `Required in-stock ${target} · Cart ${after}`;
}

function orderVerifyLineDetail(line: PlamodRestockCartReportLine): string | null {
    const preorderQty = line.preorder_arrived_qty ?? 0;
    if (preorderQty <= 0) {
        return null;
    }

    const inStockQty = line.cart_qty_after ?? 0;
    const targetInStockQty = line.target_instock_qty ?? line.requested_qty;
    const missingQty = Math.max(0, targetInStockQty - inStockQty);
    const preorderLabel = `${preorderQty} arrived preorder unit${preorderQty === 1 ? '' : 's'}`;
    if (missingQty > 0) {
        return `${preorderLabel} shown separately; ${missingQty} additional in-stock unit${missingQty === 1 ? '' : 's'} still missing.`;
    }

    return `${preorderLabel} applied toward the planned quantity; required in-stock quantity is ${targetInStockQty}.`;
}

async function refreshFromPlamod(): Promise<void> {
    syncing.value = true;
    syncMessage.value = null;
    syncFailed.value = false;
    error.value = null;
    try {
        await api.post('/api/v1/plamod/restock/sync');
        syncStatus.value = 'queued';
        stopSyncPoll();
        syncPollTimer = setInterval(() => {
            void pollSyncStatus();
        }, 3000);
        await pollSyncStatus();
    } catch (e: unknown) {
        syncing.value = false;
        error.value = e instanceof Error ? e.message : 'Failed to queue PLAMOD refresh.';
    }
}

async function saveReorderOverride(sku: string): Promise<void> {
    const raw = reorderDrafts.value[sku];
    const parsed = Number.parseInt(raw ?? '', 10);
    if (!Number.isFinite(parsed) || parsed < 0) {
        error.value = `Invalid reorder qty for ${sku}.`;
        return;
    }

    const row = proposal.value?.existing.find((entry) => entry.sku === sku);
    if (!row) {
        return;
    }

    if (!row.is_reorder_overridden && parsed === row.reorder_qty) {
        return;
    }

    reorderSavingSku.value = sku;
    error.value = null;
    try {
        await api.put(`/api/v1/plamod/restock/reorder-overrides/${encodeURIComponent(sku)}`, {
            reorder_qty: parsed === row.reorder_qty ? null : parsed,
        });
        await loadProposal({ silent: true });
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to save reorder override.';
    } finally {
        reorderSavingSku.value = null;
    }
}

async function resetReorderOverride(sku: string): Promise<void> {
    reorderSavingSku.value = sku;
    error.value = null;
    try {
        await api.put(`/api/v1/plamod/restock/reorder-overrides/${encodeURIComponent(sku)}`, {
            reorder_qty: null,
        });
        await loadProposal({ silent: true });
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to reset reorder override.';
    } finally {
        reorderSavingSku.value = null;
    }
}

async function saveMaintainQty(productUuid: string): Promise<void> {
    const raw = maintainDrafts.value[productUuid];
    const parsed = Number.parseInt(raw ?? '', 10);
    if (!Number.isFinite(parsed) || parsed < 0) {
        error.value = 'Maintain qty must be zero or greater.';
        return;
    }

    const row = proposal.value?.existing.find((entry) => entry.product_uuid === productUuid);
    if (!row || parsed === row.maintain_qty) {
        return;
    }

    maintainSavingUuid.value = productUuid;
    error.value = null;
    try {
        await api.patch(`/api/v1/products/${productUuid}/maintain`, {
            maintain: parsed,
        });
        await loadProposal({ silent: true });
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to save maintain qty.';
    } finally {
        maintainSavingUuid.value = null;
    }
}

function toggleExistingSort(key: PlamodRestockExistingSortKey): void {
    if (existingSortBy.value === key) {
        existingSortDir.value = existingSortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }

    existingSortBy.value = key;
    existingSortDir.value = 'asc';
}

function existingSortIndicator(key: PlamodRestockExistingSortKey): string {
    if (existingSortBy.value !== key) {
        return '↕';
    }

    return existingSortDir.value === 'asc' ? '↑' : '↓';
}

async function setDecision(
    sku: string,
    status: 'dismissed' | 'included' | 'later',
    orderQty?: number,
    plannedMaintainQty?: number,
    options: { silent?: boolean } = {},
): Promise<void> {
    await api.put(`/api/v1/plamod/restock/decisions/${encodeURIComponent(sku)}`, {
        status,
        order_qty: orderQty ?? null,
        planned_maintain_qty: plannedMaintainQty ?? null,
    });
    await loadProposal({ silent: options.silent });
}

async function dismissSku(sku: string): Promise<void> {
    try {
        await setDecision(sku, 'dismissed');
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to dismiss SKU.';
    }
}

async function laterSku(sku: string): Promise<void> {
    try {
        await setDecision(sku, 'later');
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to defer SKU.';
    }
}

async function submitInclude(): Promise<void> {
    if (!includeDraft.value) return;
    includeSaving.value = true;
    error.value = null;
    try {
        const orderQty = Number.parseInt(includeDraft.value.orderQty, 10);
        const maintainQty = Number.parseInt(includeDraft.value.maintainQty, 10);
        await setDecision(includeDraft.value.sku, 'included', orderQty, maintainQty);
        includeDraft.value = null;
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to include SKU.';
    } finally {
        includeSaving.value = false;
    }
}

async function createDraftPo(): Promise<void> {
    creatingDraft.value = true;
    draftMessage.value = null;
    draftError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                purchase_order_uuid: string;
                existing_added: number;
                new_added: number;
                skipped_existing_on_po: number;
                skipped_zero_qty: number;
                undecided_new_skus: number;
                dismissed_new_skus: number;
            };
        }>('/api/v1/plamod/restock/draft-purchase-order');
        const d = res.data.data;
        draftMessage.value =
            `Draft PO ${d.purchase_order_uuid.slice(0, 8)} created. ` +
            `Added ${d.existing_added} existing + ${d.new_added} new line(s). ` +
            `Skipped on PO: ${d.skipped_existing_on_po}. ` +
            `Undecided new SKUs: ${d.undecided_new_skus}. Dismissed: ${d.dismissed_new_skus}.`;
        window.location.assign(`/purchase-orders/${d.purchase_order_uuid}`);
    } catch (e: unknown) {
        draftError.value = e instanceof Error ? e.message : 'Failed to create draft PO.';
    } finally {
        creatingDraft.value = false;
    }
}

const snapshotLabel = computed(() => {
    const syncedAt = proposal.value?.snapshot.synced_at;
    if (!syncedAt) return 'No PLAMOD in-stock snapshot yet';
    return `Snapshot: ${formatLocalDateTime(syncedAt)} · ${proposal.value?.snapshot.item_count ?? 0} SKUs`;
});

const restockTotals = computed(() => proposal.value?.totals ?? null);
const restockCostGroups = computed(() => {
    if (!restockTotals.value) {
        return [];
    }

    const emptyBreakdown = {
        unique_products: 0,
        units: 0,
        product: '0.00',
        shipping: '0.00',
        landed: '0.00',
        lines_with_missing_price: 0,
    };

    return [
        { key: 'total', label: 'Total', totals: restockTotals.value },
        {
            key: 'existing',
            label: 'Existing products',
            totals: restockTotals.value.existing ?? emptyBreakdown,
        },
        {
            key: 'new',
            label: 'New products',
            totals: restockTotals.value.new_products ?? emptyBreakdown,
        },
    ];
});

const filteredExistingRows = computed(() => {
    const rows = proposal.value?.existing ?? [];
    const globallyFiltered = filterPlamodRestockExistingRows(rows, {
        search: tableSearch.value,
        type: existingType.value,
    });
    const filtered = filterPlamodRestockExistingRows(globallyFiltered, {
        search: existingSearch.value,
        type: '',
    });

    return sortPlamodRestockExistingRows(filtered, existingSortBy.value, existingSortDir.value);
});

const visibleExistingSuggestedSummary = computed(() =>
    calculatePlamodRestockSuggestedSummary(filteredExistingRows.value),
);

const existingTypeOptions = computed(() =>
    uniquePlamodRestockExistingTypes(proposal.value?.existing ?? []),
);

const visibleExistingBudget = computed(() =>
    calculatePlamodRestockExistingBudget(
        filteredExistingRows.value,
        proposal.value?.shipping_percent ?? 0,
    ),
);

const filteredNewRows = computed(() => {
    const rows = proposal.value?.new_products ?? [];
    const filtered = filterPlamodRestockNewRows(rows, {
        search: tableSearch.value,
        undecidedOnly: filterUndecidedOnly.value,
        laterOnly: filterLaterOnly.value,
        dismissedOnly: filterDismissedOnly.value,
        includedOnly: onlyIncludedNew.value,
        recentOnly: filterRecentOnly.value,
        series: filterSeries.value,
    });

    return sortPlamodRestockNewRows(filtered, newSortBy.value, newSortDir.value);
});

const newSeriesOptions = computed(() =>
    uniquePlamodRestockSeries(proposal.value?.new_products ?? []),
);
const excludedSeries = computed<string[]>(() => proposal.value?.exclusions?.excluded_series ?? []);
const excludedProductTerms = computed<string[]>(
    () => proposal.value?.exclusions?.excluded_product_terms ?? [],
);
const availableExclusionSeries = computed<string[]>(() =>
    newSeriesOptions.value.filter(
        (series) =>
            !excludedSeries.value.some(
                (excluded) =>
                    excluded.localeCompare(series, undefined, { sensitivity: 'base' }) === 0,
            ),
    ),
);

const selectedNewSkuList = computed(() =>
    Object.entries(selectedNewSkus.value)
        .filter(([, selected]) => selected)
        .map(([sku]) => sku),
);

const allVisibleNewSelected = computed(() => {
    const visible = filteredNewRows.value;
    if (visible.length === 0) {
        return false;
    }

    return visible.every((row) => selectedNewSkus.value[row.sku] === true);
});

const newFiltersActive = computed(
    () =>
        filterUndecidedOnly.value ||
        filterLaterOnly.value ||
        filterDismissedOnly.value ||
        onlyIncludedNew.value ||
        filterRecentOnly.value ||
        filterSeries.value.trim() !== '',
);

const tableSearchActive = computed(() => tableSearch.value.trim() !== '');
const existingSearchActive = computed(() => existingSearch.value.trim() !== '');

const syncProgressLabel = computed((): string => {
    const counts = syncCounts.value;
    const phase = String(counts.phase ?? '');
    if (phase === 'discover') {
        return 'Discovering PLAMOD in-stock filters…';
    }
    if (phase === 'export') {
        const processed = Number(counts.filters_processed ?? 0);
        const total = Number(counts.filters_total ?? 0);
        const current = String(counts.current_filter ?? '').trim();
        if (total > 0) {
            const suffix = current !== '' ? ` · ${current}` : '';
            return `Exporting filters ${processed}/${total}${suffix}`;
        }
        return 'Exporting PLAMOD in-stock catalog…';
    }
    if (phase === 'pdp_enrich') {
        const done = Number(counts.pdp_enrich_done ?? 0);
        const total = Number(counts.pdp_enrich_total ?? 0);
        if (total > 0) {
            return `Enriching PLAMOD prices ${done}/${total}`;
        }
        return 'Enriching PLAMOD prices…';
    }
    if (phase === 'import') {
        return 'Importing snapshot into ERP…';
    }
    if (syncStatus.value === 'queued') {
        return 'Queued PLAMOD refresh…';
    }
    if (syncStatus.value === 'running') {
        return 'Refreshing PLAMOD in-stock catalog…';
    }
    return '';
});

const refreshButtonLabel = computed((): string => {
    if (!syncing.value) {
        return 'Refresh from PLAMOD';
    }
    return syncProgressLabel.value || 'Refreshing…';
});

const cartProgressLabel = computed((): string => {
    const counts = cartRunCounts.value;
    const phase = String(counts.phase ?? '');
    const processed = Number(counts.items_processed ?? 0);
    const total = Number(counts.items_total ?? 0);
    const currentSku = String(counts.current_sku ?? '').trim();

    if (phase === 'snapshot_before') {
        return 'Reading current PLAMOD cart…';
    }
    if (phase === 'adding') {
        const suffix = currentSku !== '' ? ` · ${currentSku}` : '';
        if (total > 0) {
            return `Adding to PLAMOD cart ${processed}/${total}${suffix}`;
        }
        return `Adding to PLAMOD cart${suffix}`;
    }
    if (phase === 'verifying_cart') {
        return 'Verifying PLAMOD cart contents…';
    }
    if (cartRunStatus.value === 'queued') {
        return 'Queued PLAMOD cart automation…';
    }
    if (cartRunStatus.value === 'running') {
        return 'Running PLAMOD cart automation…';
    }
    return '';
});

const cartButtonLabel = computed((): string => {
    if (!cartRunning.value) {
        const count = selectedCartSkuList.value.length;
        if (count === 0) {
            return 'Set selected in PLAMOD cart';
        }

        return `Set selected in PLAMOD cart (${count})`;
    }
    return cartProgressLabel.value || 'Setting cart quantities…';
});

function isExistingCartEligible(row: { proposed_qty?: number }): boolean {
    return (row.proposed_qty ?? 0) > 0;
}

function isNewCartEligible(row: PlamodRestockNewRow): boolean {
    return row.status === 'included' && (row.order_qty ?? 0) > 0;
}

const cartEligibleExistingRows = computed(() =>
    filteredExistingRows.value.filter((row) => isExistingCartEligible(row)),
);

const cartEligibleNewRows = computed(() =>
    filteredNewRows.value.filter((row) => isNewCartEligible(row)),
);

const selectedCartSkuList = computed(() =>
    Object.entries(selectedCartSkus.value)
        .filter(([, selected]) => selected)
        .map(([sku]) => sku),
);

const selectedCartUnits = computed((): number => {
    if (!proposal.value) {
        return 0;
    }

    let total = 0;
    for (const sku of selectedCartSkuList.value) {
        const existing = proposal.value.existing.find((row) => row.sku === sku);
        if (existing && isExistingCartEligible(existing)) {
            total += existing.proposed_qty ?? 0;
            continue;
        }

        const newer = proposal.value.new_products.find((row) => row.sku === sku);
        if (newer && isNewCartEligible(newer)) {
            total += newer.order_qty ?? 0;
        }
    }

    return total;
});

const allVisibleExistingCartSelected = computed(() => {
    const visible = cartEligibleExistingRows.value;
    if (visible.length === 0) {
        return false;
    }

    return visible.every((row) => selectedCartSkus.value[row.sku] === true);
});

const allVisibleNewCartSelected = computed(() => {
    const visible = cartEligibleNewRows.value;
    if (visible.length === 0) {
        return false;
    }

    return visible.every((row) => selectedCartSkus.value[row.sku] === true);
});

function toggleSelectAllExistingCart(checked: boolean): void {
    const next = { ...selectedCartSkus.value };
    for (const row of cartEligibleExistingRows.value) {
        next[row.sku] = checked;
    }
    selectedCartSkus.value = next;
}

function toggleSelectAllNewCart(checked: boolean): void {
    const next = { ...selectedCartSkus.value };
    for (const row of cartEligibleNewRows.value) {
        next[row.sku] = checked;
    }
    selectedCartSkus.value = next;
}

function clearCartSelection(): void {
    selectedCartSkus.value = {};
}

function pruneCartSelection(): void {
    if (!proposal.value) {
        selectedCartSkus.value = {};
        return;
    }

    const next = { ...selectedCartSkus.value };
    for (const sku of Object.keys(next)) {
        const existing = proposal.value.existing.find((row) => row.sku === sku);
        if (existing) {
            if (!isExistingCartEligible(existing)) {
                delete next[sku];
            }
            continue;
        }

        const newer = proposal.value.new_products.find((row) => row.sku === sku);
        if (!newer || !isNewCartEligible(newer)) {
            delete next[sku];
        }
    }

    selectedCartSkus.value = next;
}

const cartReportLines = computed(() => cartReport.value?.lines ?? []);

const cartRetryableSkuList = computed(() => collectCartReportRetryableSkus(cartReportLines.value));

const orderEligibleLineCount = computed((): number => {
    if (!proposal.value) {
        return 0;
    }

    let count = 0;
    for (const row of proposal.value.existing) {
        if ((row.proposed_qty ?? 0) > 0) {
            count += 1;
        }
    }
    for (const row of proposal.value.new_products) {
        if (row.status === 'included') {
            count += 1;
        }
    }

    return count;
});

const orderVerifyReportLines = computed(() => {
    const lines = orderVerifyReport.value?.lines ?? [];
    if (!orderVerifyMismatchesOnly.value) {
        return lines;
    }

    return lines.filter((line) => isOrderVerifyLineMismatch(line.verification_status));
});

const orderVerifyExtraLines = computed(() => orderVerifyReport.value?.extra_cart_lines ?? []);
const orderVerifyRepairableSkuList = computed(() =>
    collectCartReportRetryableSkus(orderVerifyReport.value?.lines ?? []),
);

const orderVerifyButtonLabel = computed((): string => {
    if (orderVerifying.value) {
        return 'Verifying order…';
    }

    return `Verify full order (${orderEligibleLineCount.value})`;
});

watch(
    proposal,
    () => {
        pruneCartSelection();
    },
    { deep: true },
);

watch([requestHideDismissed, requestOnlyIncludedNew], () => {
    void loadProposal();
});

watch(
    [
        activeTab,
        tableSearch,
        existingSearch,
        existingType,
        hideDismissed,
        onlyIncludedNew,
        filterUndecidedOnly,
        filterLaterOnly,
        filterDismissedOnly,
        filterRecentOnly,
        filterSeries,
        existingSortBy,
        existingSortDir,
        newSortBy,
        newSortDir,
    ],
    () => {
        persistPageState();
    },
);

onMounted(async () => {
    applyPageState();
    await loadProposal();
    await pollSyncStatus();
    await pollCartRunStatus();
    await loadOrderVerifyStatus();
});

onUnmounted(() => {
    stopSyncPoll();
    stopCartPoll();
});

function openInclude(row: PlamodRestockNewRow): void {
    includeDraft.value = {
        sku: row.sku,
        orderQty: row.order_qty != null ? String(row.order_qty) : '',
        maintainQty: row.planned_maintain_qty != null ? String(row.planned_maintain_qty) : '',
    };
}

function toggleNewSort(key: PlamodRestockNewSortKey): void {
    if (newSortBy.value === key) {
        newSortDir.value = newSortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }

    newSortBy.value = key;
    newSortDir.value = key === 'release_date' ? 'desc' : 'asc';
}

function newSortIndicator(key: PlamodRestockNewSortKey): string {
    if (newSortBy.value !== key) {
        return '↕';
    }

    return newSortDir.value === 'asc' ? '↑' : '↓';
}

function toggleSelectAllNew(checked: boolean): void {
    const next = { ...selectedNewSkus.value };
    for (const row of filteredNewRows.value) {
        next[row.sku] = checked;
    }
    selectedNewSkus.value = next;
}

function openImageOverlay(row: PlamodRestockNewRow): void {
    if (!row.image_url) {
        return;
    }

    imageOverlay.value = {
        imageUrl: row.image_url,
        alt: row.product_name,
    };
}

async function saveNewIncludedQtys(sku: string): Promise<void> {
    const row = proposal.value?.new_products.find((entry) => entry.sku === sku);
    if (!row || row.status !== 'included') {
        return;
    }

    const orderQty = Number.parseInt(newOrderDrafts.value[sku] ?? '', 10);
    const maintainQty = Number.parseInt(newMaintainDrafts.value[sku] ?? '', 10);
    if (!Number.isFinite(orderQty) || orderQty < 0) {
        error.value = `Order qty must be zero or greater for ${sku}.`;
        return;
    }
    if (!Number.isFinite(maintainQty) || maintainQty < 0) {
        error.value = `Planned maintain qty must be zero or greater for ${sku}.`;
        return;
    }

    if (orderQty === row.order_qty && maintainQty === row.planned_maintain_qty) {
        return;
    }

    newDecisionSavingSku.value = sku;
    error.value = null;
    try {
        await setDecision(sku, 'included', orderQty, maintainQty, { silent: true });
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to save included SKU qtys.';
    } finally {
        newDecisionSavingSku.value = null;
    }
}

async function excludeIncluded(sku: string): Promise<void> {
    try {
        await dismissSku(sku);
    } catch {
        // dismissSku sets error
    }
}

function openBulkInclude(): void {
    const skus = selectedNewSkuList.value;
    if (skus.length === 0) {
        return;
    }

    bulkIncludeDraft.value = {
        skus,
        orderQty: '',
        maintainQty: '',
    };
}

async function submitBulkInclude(): Promise<void> {
    if (!bulkIncludeDraft.value) {
        return;
    }

    bulkSaving.value = true;
    error.value = null;
    try {
        const orderQty = Number.parseInt(bulkIncludeDraft.value.orderQty, 10);
        const maintainQty = Number.parseInt(bulkIncludeDraft.value.maintainQty, 10);
        await api.post('/api/v1/plamod/restock/decisions/bulk', {
            skus: bulkIncludeDraft.value.skus,
            status: 'included',
            order_qty: orderQty,
            planned_maintain_qty: maintainQty,
        });
        bulkIncludeDraft.value = null;
        selectedNewSkus.value = {};
        await loadProposal();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to bulk include SKUs.';
    } finally {
        bulkSaving.value = false;
    }
}

async function bulkDismissSelected(): Promise<void> {
    const skus = selectedNewSkuList.value;
    if (skus.length === 0) {
        return;
    }

    bulkSaving.value = true;
    error.value = null;
    try {
        await api.post('/api/v1/plamod/restock/decisions/bulk', {
            skus,
            status: 'dismissed',
        });
        selectedNewSkus.value = {};
        await loadProposal();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to bulk dismiss SKUs.';
    } finally {
        bulkSaving.value = false;
    }
}

async function bulkLaterSelected(): Promise<void> {
    const skus = selectedNewSkuList.value;
    if (skus.length === 0) {
        return;
    }

    bulkSaving.value = true;
    error.value = null;
    try {
        await api.post('/api/v1/plamod/restock/decisions/bulk', {
            skus,
            status: 'later',
        });
        selectedNewSkus.value = {};
        await loadProposal();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Failed to bulk defer SKUs.';
    } finally {
        bulkSaving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-[1400px] space-y-6 p-4 md:p-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">PLAMOD restock</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Bandai Hobby · Plastic Model Kits · In-Stock intersected with ERP reorder needs.
                </p>
                <p class="mt-1 text-sm text-slate-500" data-testid="restock-snapshot-label">
                    {{ snapshotLabel }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="syncing || cartRunning"
                    :title="cartRunning ? 'Wait for the active cart run to finish' : undefined"
                    data-testid="restock-refresh-plamod"
                    @click="refreshFromPlamod"
                >
                    {{ refreshButtonLabel }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-900 hover:bg-emerald-100 disabled:opacity-50"
                    :disabled="
                        syncing ||
                        cartRunning ||
                        orderVerifying ||
                        loading ||
                        orderEligibleLineCount === 0
                    "
                    :title="
                        orderEligibleLineCount === 0
                            ? 'No order lines with quantity > 0 to verify'
                            : 'Compare every order line and quantity against your live PLAMOD cart'
                    "
                    data-testid="restock-verify-full-order"
                    @click="verifyFullOrderAgainstPlamodCart"
                >
                    {{ orderVerifyButtonLabel }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-sky-200 bg-sky-50 px-3 py-1.5 text-sm font-medium text-sky-900 hover:bg-sky-100 disabled:opacity-50"
                    :disabled="cartRunning || loading || selectedCartSkuList.length === 0"
                    :title="
                        selectedCartSkuList.length === 0
                            ? 'Select rows using Cart checkboxes in the tables below'
                            : undefined
                    "
                    data-testid="restock-add-to-plamod-cart"
                    @click="addProposalToPlamodCart"
                >
                    {{ cartButtonLabel }}
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    :disabled="creatingDraft || loading"
                    data-testid="restock-create-draft-po"
                    @click="createDraftPo"
                >
                    {{ creatingDraft ? 'Creating draft PO…' : 'Create draft PO' }}
                </button>
            </div>
        </div>

        <div
            class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 md:flex-row md:items-end md:justify-between"
        >
            <label class="text-sm text-slate-700">
                Shipping estimate (% of product cost)
                <div class="mt-1 flex items-center gap-2">
                    <input
                        v-model="shippingPercentInput"
                        type="number"
                        min="0"
                        max="100"
                        step="0.1"
                        class="w-24 rounded-md border border-slate-200 px-2 py-1.5 text-sm"
                        data-testid="restock-shipping-percent"
                    />
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50"
                        :disabled="savingShipping"
                        data-testid="restock-save-shipping"
                        @click="saveShippingPercent"
                    >
                        Save
                    </button>
                </div>
            </label>
            <div class="flex flex-wrap gap-4 text-sm text-slate-700">
                <label class="min-w-[220px] flex-1 text-sm text-slate-700">
                    Search SKU or product name
                    <input
                        v-model="tableSearch"
                        type="search"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-1.5 text-sm"
                        placeholder="SKU, barcode, product name…"
                        data-testid="restock-table-search"
                    />
                </label>
            </div>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>
        <div
            v-if="syncMessage"
            class="rounded-md px-3 py-2 text-sm"
            :class="
                syncFailed
                    ? 'border border-rose-200 bg-rose-50 text-rose-800'
                    : 'border border-emerald-200 bg-emerald-50 text-emerald-800'
            "
        >
            {{ syncMessage }}
        </div>
        <div
            v-if="draftError"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ draftError }}
        </div>

        <div
            v-if="syncing && syncProgressLabel"
            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            data-testid="restock-sync-progress"
        >
            {{ syncProgressLabel }}
        </div>

        <div
            v-if="cartRunning && cartProgressLabel"
            class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900"
            data-testid="restock-cart-progress"
        >
            {{ cartProgressLabel }}
        </div>

        <div
            v-if="orderVerifyMessage"
            class="rounded-md px-3 py-2 text-sm"
            :class="
                orderVerifyFailed
                    ? 'border border-amber-200 bg-amber-50 text-amber-950'
                    : 'border border-emerald-200 bg-emerald-50 text-emerald-800'
            "
            data-testid="restock-order-verify-headline"
        >
            {{ orderVerifyMessage }}
        </div>
        <div
            v-if="orderVerifyError"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            data-testid="restock-order-verify-error"
        >
            {{ orderVerifyError }}
        </div>

        <section
            v-if="orderVerifyReport && orderVerifyMessage"
            class="rounded-lg border border-slate-200 bg-white"
            data-testid="restock-order-verify-report"
        >
            <div
                class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Full order verification</h2>
                    <p class="mt-1 text-xs text-slate-600">
                        Compares each required in-stock quantity against the IN-STOCK quantity in
                        your live PLAMOD cart. For New on PLAMOD products, arrived preorders count
                        toward the planned quantity; existing-product suggestions already account
                        for open purchase orders.
                        <span
                            v-if="orderVerifyReport.verified_at"
                            class="block pt-1 text-slate-500"
                        >
                            Last verified
                            {{ new Date(orderVerifyReport.verified_at).toLocaleString() }}.
                            <span
                                v-if="orderVerifyReport.cart_lines_detected != null"
                                class="block"
                            >
                                Detected {{ orderVerifyReport.cart_lines_detected }}
                                <template v-if="orderVerifyReport.cart_item_badge_count">
                                    of {{ orderVerifyReport.cart_item_badge_count }}
                                </template>
                                cart line(s) on PLAMOD.
                            </span>
                        </span>
                    </p>
                </div>
                <div class="flex flex-col gap-2 sm:items-end">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="orderVerifyRepairableSkuList.length > 0"
                            type="button"
                            class="rounded-md bg-sky-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                            :disabled="cartRunning || orderVerifying || loading"
                            data-testid="restock-order-verify-fix-mismatches"
                            @click="fixFullOrderCartMismatches"
                        >
                            Fix mismatches ({{ orderVerifyRepairableSkuList.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-900 hover:bg-emerald-100 disabled:opacity-50"
                            :disabled="cartRunning || orderVerifying || loading"
                            data-testid="restock-order-verify-rerun"
                            @click="verifyFullOrderAgainstPlamodCart"
                        >
                            {{ orderVerifying ? 'Verifying…' : 'Verify again' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="orderVerifying"
                            data-testid="restock-order-verify-dismiss"
                            @click="dismissOrderVerifyReport"
                        >
                            Dismiss
                        </button>
                    </div>
                    <a
                        v-if="orderVerifyReport.cart_url"
                        :href="orderVerifyReport.cart_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm font-medium text-sky-700 hover:underline"
                    >
                        Open PLAMOD cart
                    </a>
                </div>
            </div>

            <div
                v-if="orderVerifyExtraLines.length > 0"
                class="border-b border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                data-testid="restock-order-verify-extra-lines"
            >
                <p class="font-medium">
                    {{ orderVerifyExtraLines.length }} extra cart line(s) not in your order
                </p>
                <p class="mt-1 text-xs text-amber-900">
                    These SKUs are in PLAMOD cart but not part of your restock order. Remove them
                    manually if they should not be ordered.
                </p>
                <ul class="mt-2 space-y-1 text-xs">
                    <li
                        v-for="line in orderVerifyExtraLines"
                        :key="`extra-${line.sku}`"
                        class="tabular-nums"
                    >
                        {{ line.sku }} · cart qty {{ line.cart_qty }}
                    </li>
                </ul>
            </div>

            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2">
                <label class="flex items-center gap-2 text-xs text-slate-700">
                    <input
                        v-model="orderVerifyMismatchesOnly"
                        type="checkbox"
                        class="rounded border-slate-300"
                        data-testid="restock-order-verify-mismatches-only"
                    />
                    Show mismatches only
                </label>
                <span class="text-xs text-slate-500">
                    {{ orderVerifyReport.summary.requested_lines }} order line(s)
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead
                        class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"
                    >
                        <tr>
                            <th class="px-4 py-2">SKU</th>
                            <th class="px-4 py-2">Product</th>
                            <th class="px-4 py-2 text-right">Planned qty</th>
                            <th class="px-4 py-2 text-right">Arrived preorder qty</th>
                            <th class="px-4 py-2 text-right">Required in-stock qty</th>
                            <th class="px-4 py-2 text-right">In-stock cart qty</th>
                            <th class="px-4 py-2">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in orderVerifyReportLines"
                            :key="`order-verify-${line.sku}`"
                            class="border-t border-slate-100"
                            :data-testid="`restock-order-verify-row-${line.sku}`"
                        >
                            <td class="px-4 py-2 font-medium tabular-nums text-slate-900">
                                {{ line.sku }}
                            </td>
                            <td class="px-4 py-2">
                                <a
                                    v-if="line.product_name"
                                    :href="buildPlamodPdpUrl(line.sku) ?? undefined"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-medium text-sky-700 hover:text-sky-900 hover:underline"
                                    :data-testid="`restock-order-verify-product-${line.sku}`"
                                    :title="`Open ${line.sku} on PLAMOD`"
                                >
                                    {{ line.product_name }}
                                </a>
                                <span v-else class="text-slate-500">—</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.requested_qty }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.preorder_arrived_qty ?? 0 }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.target_instock_qty ?? line.requested_qty }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.cart_qty_after ?? 0 }}
                            </td>
                            <td class="px-4 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="cartVerificationClass(line.verification_status)"
                                >
                                    {{
                                        plamodRestockCartVerificationLabel(line.verification_status)
                                    }}
                                </span>
                                <div v-if="line.error_message" class="mt-1 text-xs text-rose-700">
                                    <span class="font-semibold">PLAMOD message:</span>
                                    {{ line.error_message }}
                                </div>
                                <div
                                    v-if="orderVerifyLineDetail(line)"
                                    class="mt-1 text-xs text-slate-600"
                                >
                                    {{ orderVerifyLineDetail(line) }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="orderVerifyReportLines.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-6 text-center text-sm text-emerald-700"
                                data-testid="restock-order-verify-all-match"
                            >
                                All order lines match PLAMOD cart quantities.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="cartRunMessage"
            class="rounded-md px-3 py-2 text-sm"
            :class="
                cartRunFailed
                    ? 'border border-amber-200 bg-amber-50 text-amber-950'
                    : 'border border-emerald-200 bg-emerald-50 text-emerald-800'
            "
            data-testid="restock-cart-report-headline"
        >
            {{ cartRunMessage }}
        </div>
        <div
            v-if="cartRunError"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            data-testid="restock-cart-report-error"
        >
            {{ cartRunError }}
        </div>

        <section
            v-if="cartReportLines.length > 0"
            class="rounded-lg border border-slate-200 bg-white"
            data-testid="restock-cart-report"
        >
            <div
                class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">
                        PLAMOD cart verification report
                    </h2>
                    <p class="mt-1 text-xs text-slate-600">
                        Compares requested restock lines against PLAMOD cart before/after
                        quantities. Recheck after a manual cart fix. Retry uses arrived preorders
                        when calculating the required in-stock cart quantity.
                        <span v-if="cartReport?.rechecked_at" class="block pt-1 text-slate-500">
                            Last rechecked {{ new Date(cartReport.rechecked_at).toLocaleString() }}.
                        </span>
                    </p>
                </div>
                <div class="flex flex-col gap-2 sm:items-end">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="cartRetryableSkuList.length > 0"
                            type="button"
                            class="rounded-md bg-sky-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                            :disabled="cartRunning || cartRechecking || loading"
                            data-testid="restock-cart-retry-failed"
                            @click="retryFailedCartLines"
                        >
                            Retry remaining ({{ cartRetryableSkuList.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="cartRunning || cartRechecking || loading"
                            data-testid="restock-cart-recheck"
                            @click="recheckPlamodCart"
                        >
                            {{ cartRechecking ? 'Rechecking…' : 'Recheck cart' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="cartRunning || cartRechecking"
                            data-testid="restock-cart-dismiss"
                            @click="dismissCartReport"
                        >
                            Dismiss
                        </button>
                    </div>
                    <a
                        v-if="cartReport?.cart_url"
                        :href="cartReport.cart_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm font-medium text-sky-700 hover:underline"
                    >
                        Open PLAMOD cart
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead
                        class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"
                    >
                        <tr>
                            <th class="px-4 py-2">SKU</th>
                            <th class="px-4 py-2">Product</th>
                            <th class="px-4 py-2 text-right">Planned qty</th>
                            <th class="px-4 py-2 text-right">Arrived preorder qty</th>
                            <th class="px-4 py-2 text-right">Required in-stock qty</th>
                            <th class="px-4 py-2 text-right">In-stock cart qty</th>
                            <th class="px-4 py-2">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in cartReportLines"
                            :key="line.sku"
                            class="border-t border-slate-100"
                            :data-testid="`restock-cart-report-row-${line.sku}`"
                        >
                            <td class="px-4 py-2 font-medium tabular-nums text-slate-900">
                                {{ line.sku }}
                            </td>
                            <td class="px-4 py-2 text-slate-700">{{ line.product_name || '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.requested_qty }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.preorder_arrived_qty ?? 0 }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.target_instock_qty ?? line.requested_qty }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ line.cart_qty_after ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="cartVerificationClass(line.verification_status)"
                                >
                                    {{
                                        plamodRestockCartVerificationLabel(line.verification_status)
                                    }}
                                </span>
                                <div v-if="line.error_message" class="mt-1 text-xs text-rose-700">
                                    <span class="font-semibold">PLAMOD message:</span>
                                    {{ line.error_message }}
                                </div>
                                <div v-else class="mt-1 text-xs text-slate-500">
                                    {{ cartReportLineLabel(line) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="restockTotals"
            class="rounded-lg border border-slate-200 bg-slate-50 p-4"
            data-testid="restock-totals"
        >
            <h2 class="text-sm font-semibold text-slate-900">Restock cost breakdown</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <article
                    v-for="group in restockCostGroups"
                    :key="group.key"
                    class="rounded-md border p-3"
                    :class="
                        group.key === 'total'
                            ? 'border-slate-300 bg-white'
                            : 'border-slate-200 bg-slate-50'
                    "
                    :data-testid="`restock-cost-${group.key}`"
                >
                    <h3 class="text-sm font-semibold text-slate-900">{{ group.label }}</h3>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500">Unique products</dt>
                            <dd class="font-semibold tabular-nums text-slate-900">
                                {{ group.totals.unique_products }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Units</dt>
                            <dd class="font-semibold tabular-nums text-slate-900">
                                {{ group.totals.units }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Product</dt>
                            <dd class="font-semibold tabular-nums text-slate-900">
                                ${{ group.totals.product }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">
                                Shipping ({{ proposal?.shipping_percent ?? 0 }}%)
                            </dt>
                            <dd class="font-semibold tabular-nums text-slate-900">
                                ${{ group.totals.shipping }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Landed</dt>
                            <dd class="text-base font-semibold tabular-nums text-slate-900">
                                ${{ group.totals.landed }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="group.totals.lines_with_missing_price > 0"
                        class="mt-2 text-xs text-amber-700"
                    >
                        {{ group.totals.lines_with_missing_price }} line(s) missing price
                    </p>
                </article>
            </div>
        </section>

        <div
            v-if="selectedCartSkuList.length > 0"
            class="flex flex-wrap items-center gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3"
            data-testid="restock-cart-selection-bar"
        >
            <span class="text-sm text-sky-950">
                {{ selectedCartSkuList.length }} SKU(s) selected · {{ selectedCartUnits }} unit(s)
            </span>
            <button
                type="button"
                class="rounded-md border border-sky-300 bg-white px-3 py-1.5 text-sm text-sky-900 hover:bg-sky-100"
                :disabled="cartRunning"
                data-testid="restock-cart-selection-clear"
                @click="clearCartSelection"
            >
                Clear selection
            </button>
        </div>

        <nav
            class="flex gap-1 rounded-lg border border-slate-200 bg-slate-100 p-1"
            role="tablist"
            aria-label="PLAMOD restock product groups"
            data-testid="restock-tabs"
        >
            <button
                id="restock-tab-existing"
                type="button"
                role="tab"
                :aria-selected="activeTab === 'existing'"
                aria-controls="restock-panel-existing"
                class="flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    activeTab === 'existing'
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'
                "
                data-testid="restock-tab-existing"
                @click="activeTab = 'existing'"
            >
                Existing products
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs tabular-nums">
                    {{ proposal?.meta.existing_count ?? 0 }}
                </span>
            </button>
            <button
                id="restock-tab-new"
                type="button"
                role="tab"
                :aria-selected="activeTab === 'new'"
                aria-controls="restock-panel-new"
                class="flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    activeTab === 'new'
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'
                "
                data-testid="restock-tab-new"
                @click="activeTab = 'new'"
            >
                New on PLAMOD
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs tabular-nums">
                    {{ proposal?.meta.new_count ?? 0 }}
                </span>
            </button>
        </nav>

        <section
            v-show="activeTab === 'existing'"
            id="restock-panel-existing"
            class="rounded-lg border border-slate-200 bg-white"
            role="tabpanel"
            aria-labelledby="restock-tab-existing"
            data-testid="restock-existing-panel"
        >
            <div class="border-b border-slate-200 px-4 py-3">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">
                            Existing products (ERP ∩ PLAMOD in-stock)
                            <span class="font-normal text-slate-500">
                                ·
                                <template
                                    v-if="
                                        tableSearchActive ||
                                        existingSearchActive ||
                                        existingType !== ''
                                    "
                                >
                                    {{ filteredExistingRows.length }} of
                                    {{ proposal?.meta.existing_count ?? 0 }} rows
                                </template>
                                <template v-else
                                    >{{ proposal?.meta.existing_count ?? 0 }} rows</template
                                >
                            </span>
                        </h2>
                        <p
                            class="mt-1 text-xs text-slate-600"
                            data-testid="restock-existing-suggested-summary"
                        >
                            System suggests
                            {{ visibleExistingSuggestedSummary.uniqueProducts }} unique
                            {{
                                visibleExistingSuggestedSummary.uniqueProducts === 1
                                    ? 'product'
                                    : 'products'
                            }}
                            · {{ visibleExistingSuggestedSummary.units }}
                            {{ visibleExistingSuggestedSummary.units === 1 ? 'unit' : 'units' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="min-w-[220px] text-sm text-slate-700">
                            Search existing products
                            <input
                                v-model="existingSearch"
                                type="search"
                                class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-1.5 text-sm"
                                placeholder="SKU, barcode, product name…"
                                data-testid="restock-existing-search"
                            />
                        </label>
                        <label class="text-sm text-slate-700">
                            Product type
                            <select
                                v-model="existingType"
                                class="mt-1 block rounded-md border border-slate-200 px-2 py-1.5 text-sm"
                                data-testid="restock-existing-type-filter"
                            >
                                <option value="">All types</option>
                                <option
                                    v-for="type in existingTypeOptions"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ type }}
                                </option>
                            </select>
                        </label>
                    </div>
                </div>
                <div
                    v-if="existingType !== ''"
                    class="mt-3 flex flex-wrap gap-x-5 gap-y-1 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-950"
                    data-testid="restock-existing-type-budget"
                >
                    <strong>{{ existingType }} budget</strong>
                    <span>{{ visibleExistingBudget.skuCount }} SKU(s)</span>
                    <span>{{ visibleExistingBudget.units }} unit(s)</span>
                    <span>Product ${{ visibleExistingBudget.product }}</span>
                    <span>Shipping ${{ visibleExistingBudget.shipping }}</span>
                    <span>Landed ${{ visibleExistingBudget.landed }}</span>
                    <span
                        v-if="visibleExistingBudget.linesWithMissingPrice > 0"
                        class="text-amber-800"
                    >
                        {{ visibleExistingBudget.linesWithMissingPrice }} line(s) missing price
                    </span>
                </div>
            </div>
            <div class="max-h-[calc(100vh-10rem)] overflow-auto">
                <table
                    class="min-w-full divide-y divide-slate-200 text-sm"
                    data-testid="restock-existing-table"
                >
                    <thead
                        class="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600 shadow-sm"
                    >
                        <tr>
                            <th class="px-3 py-2" data-testid="restock-existing-cart-column">
                                <label class="flex cursor-pointer flex-col items-center gap-1">
                                    <span>Cart</span>
                                    <input
                                        type="checkbox"
                                        :checked="allVisibleExistingCartSelected"
                                        :disabled="cartEligibleExistingRows.length === 0"
                                        aria-label="Select all visible existing products for the PLAMOD cart"
                                        data-testid="restock-existing-cart-select-all"
                                        @change="
                                            toggleSelectAllExistingCart(
                                                ($event.target as HTMLInputElement).checked,
                                            )
                                        "
                                    />
                                </label>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleExistingSort('sku')"
                                >
                                    SKU
                                    <span class="text-[10px]">{{
                                        existingSortIndicator('sku')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleExistingSort('type')"
                                >
                                    Type
                                    <span class="text-[10px]">{{
                                        existingSortIndicator('type')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleExistingSort('product_name')"
                                >
                                    Product
                                    <span class="text-[10px]">{{
                                        existingSortIndicator('product_name')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleExistingSort('release_date')"
                                >
                                    Release
                                    <span class="text-[10px]">{{
                                        existingSortIndicator('release_date')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('available_qty')"
                                    >
                                        Available
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('available_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="AVAILABLE_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('maintain_qty')"
                                    >
                                        Maintain
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('maintain_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="MAINTAIN_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('not_arrived_qty')"
                                    >
                                        Not arrived
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('not_arrived_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="NOT_ARRIVED_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('preorder_committed_qty')"
                                    >
                                        Preorders
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('preorder_committed_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="PREORDER_COMMITTED_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('reorder_qty')"
                                    >
                                        Suggested
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('reorder_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="SUGGESTED_REORDER_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('proposed_qty')"
                                    >
                                        Order qty
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('proposed_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="REORDER_OVERRIDE_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleExistingSort('last_product_cost')"
                                >
                                    Last cost
                                    <span class="text-[10px]">{{
                                        existingSortIndicator('last_product_cost')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <span class="inline-flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleExistingSort('new_product_cost')"
                                    >
                                        New cost
                                        <span class="text-[10px]">{{
                                            existingSortIndicator('new_product_cost')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="NEW_COST_DELTA_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="inline-flex w-full items-center justify-end gap-1 hover:text-slate-900"
                                    @click="toggleExistingSort('line_total')"
                                >
                                    Line total
                                    <span class="text-[10px]">{{
                                        existingSortIndicator('line_total')
                                    }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="loading && proposal === null">
                            <td colspan="14" class="px-3 py-6 text-center text-slate-500">
                                Loading…
                            </td>
                        </tr>
                        <tr
                            v-for="row in filteredExistingRows"
                            :key="row.sku"
                            class="hover:bg-slate-50"
                        >
                            <td class="px-3 py-2">
                                <input
                                    v-if="isExistingCartEligible(row)"
                                    v-model="selectedCartSkus[row.sku]"
                                    type="checkbox"
                                    :data-testid="`restock-existing-cart-select-${row.sku}`"
                                />
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ row.sku }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ row.type || '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-col gap-0.5">
                                    <a
                                        v-if="row.plamod_pdp_url"
                                        :href="row.plamod_pdp_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-blue-700 hover:underline"
                                    >
                                        {{ row.product_name }}
                                    </a>
                                    <span v-else>{{ row.product_name }}</span>
                                    <a
                                        :href="erpProductSearchUrl(row.sku)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-[11px] text-slate-500 hover:text-slate-800 hover:underline"
                                        data-testid="restock-erp-product-link"
                                    >
                                        Open in ERP
                                    </a>
                                </div>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ releaseDateLabel(row) }}
                                <span
                                    v-if="row.is_recent_release"
                                    class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-indigo-700"
                                    :title="RECENT_RELEASE_TOOLTIP"
                                >
                                    Recent
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.available_qty }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input
                                    v-model="maintainDrafts[row.product_uuid]"
                                    type="number"
                                    min="0"
                                    class="w-16 rounded-md border border-slate-200 px-2 py-1 text-right text-sm tabular-nums"
                                    :data-testid="`restock-maintain-${row.sku}`"
                                    :disabled="maintainSavingUuid === row.product_uuid"
                                    @change="saveMaintainQty(row.product_uuid)"
                                />
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.not_arrived_qty }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <PlamodRestockPreorderQtyCell
                                    :committed-qty="row.preorder_committed_qty"
                                    :shipments="row.preorder_shipments"
                                />
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-500">
                                {{ row.reorder_qty }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <input
                                        v-model="reorderDrafts[row.sku]"
                                        type="number"
                                        min="0"
                                        class="w-16 rounded-md border border-slate-200 px-2 py-1 text-right text-sm tabular-nums"
                                        :class="
                                            row.is_reorder_overridden
                                                ? 'border-indigo-300 bg-indigo-50'
                                                : ''
                                        "
                                        :data-testid="`restock-reorder-${row.sku}`"
                                        @change="saveReorderOverride(row.sku)"
                                    />
                                    <button
                                        v-if="row.is_reorder_overridden"
                                        type="button"
                                        class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-600 hover:bg-slate-50"
                                        :disabled="reorderSavingSku === row.sku"
                                        @click="resetReorderOverride(row.sku)"
                                    >
                                        Reset
                                    </button>
                                </div>
                            </td>
                            <td class="px-3 py-2 font-semibold tabular-nums">
                                {{ formatProductPrice(row.last_landed_cost) }}
                            </td>
                            <td
                                class="px-3 py-2"
                                :class="
                                    row.cost_delta_high
                                        ? 'rounded bg-amber-50 ring-1 ring-amber-300'
                                        : ''
                                "
                                :title="row.cost_delta_high ? NEW_COST_DELTA_TOOLTIP : ''"
                            >
                                <div class="flex items-baseline gap-2">
                                    <span
                                        class="font-semibold tabular-nums"
                                        :title="
                                            row.new_landed_cost ? '' : NEW_LANDED_MISSING_TOOLTIP
                                        "
                                    >
                                        {{ formatProductPrice(row.new_landed_cost) }}
                                    </span>
                                    <span
                                        v-if="
                                            row.cost_delta_high &&
                                            formatCostDeltaBadge(row.cost_delta_percent)
                                        "
                                        class="text-[10px] font-semibold uppercase text-amber-800"
                                    >
                                        {{ formatCostDeltaBadge(row.cost_delta_percent) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">
                                {{ formatLineTotal(row.line_total) }}
                            </td>
                        </tr>
                        <tr
                            v-if="
                                !loading &&
                                filteredExistingRows.length === 0 &&
                                (proposal?.existing?.length ?? 0) > 0
                            "
                        >
                            <td colspan="14" class="px-3 py-6 text-center text-slate-500">
                                No rows match your search and product type filters.
                            </td>
                        </tr>
                        <tr v-if="!loading && (proposal?.existing?.length ?? 0) === 0">
                            <td colspan="14" class="px-3 py-6 text-center text-slate-500">
                                No existing restock lines.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-show="activeTab === 'new'"
            id="restock-panel-new"
            class="rounded-lg border border-slate-200 bg-white"
            role="tabpanel"
            aria-labelledby="restock-tab-new"
            data-testid="restock-new-panel"
        >
            <div class="border-b border-slate-200 px-4 py-3">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">
                            New on PLAMOD (not in catalog)
                            <span class="font-normal text-slate-500">
                                ·
                                <template v-if="tableSearchActive || newFiltersActive">
                                    {{ filteredNewRows.length }} of
                                    {{ proposal?.meta.new_count ?? 0 }} rows
                                </template>
                                <template v-else>{{ proposal?.meta.new_count ?? 0 }} rows</template>
                            </span>
                        </h2>
                        <p
                            v-if="(proposal?.meta.later_new_count ?? 0) > 0"
                            class="mt-1 text-xs text-slate-600"
                            data-testid="restock-new-later-count"
                        >
                            {{ proposal?.meta.later_new_count }} SKU(s) marked Later — select Later
                            to review when budget allows.
                        </p>
                        <p
                            v-if="(proposal?.meta.new_missing_price_count ?? 0) > 0"
                            class="mt-1 text-xs text-amber-700"
                            data-testid="restock-new-missing-price"
                        >
                            {{ proposal?.meta.new_missing_price_count }} new SKU(s) missing PLAMOD
                            price — refresh from PLAMOD to backfill.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm text-slate-700">
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="hideDismissed"
                                type="checkbox"
                                data-testid="restock-hide-dismissed"
                            />
                            Hide dismissed
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="filterUndecidedOnly"
                                type="checkbox"
                                data-testid="restock-filter-undecided"
                            />
                            Undecided
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="filterLaterOnly"
                                type="checkbox"
                                data-testid="restock-filter-later"
                            />
                            Later
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="filterDismissedOnly"
                                type="checkbox"
                                data-testid="restock-filter-dismissed"
                            />
                            Dismissed
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="onlyIncludedNew"
                                type="checkbox"
                                data-testid="restock-filter-included"
                            />
                            Included
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="filterRecentOnly"
                                type="checkbox"
                                data-testid="restock-filter-recent"
                            />
                            Recent releases
                        </label>
                        <label class="inline-flex items-center gap-2">
                            Series
                            <select
                                v-model="filterSeries"
                                class="rounded-md border border-slate-200 px-2 py-1 text-sm"
                                data-testid="restock-filter-series"
                            >
                                <option value="">All</option>
                                <option
                                    v-for="series in newSeriesOptions"
                                    :key="series"
                                    :value="series"
                                >
                                    {{ series }}
                                </option>
                            </select>
                        </label>
                    </div>
                </div>
                <div
                    class="mt-3 rounded-md border border-indigo-100 bg-indigo-50/60 p-3"
                    data-testid="restock-automatic-exclusions"
                >
                    <div class="flex flex-col gap-1">
                        <h3 class="text-sm font-semibold text-slate-900">
                            Always hide future products
                        </h3>
                        <p class="text-xs text-slate-600">
                            Matching new SKUs are automatically treated as dismissed until you
                            remove the rule.
                        </p>
                    </div>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <label class="flex min-w-64 flex-1 flex-col gap-1 text-xs text-slate-600">
                            Product name contains
                            <div class="flex gap-2">
                                <input
                                    v-model="exclusionProductTermDraft"
                                    type="text"
                                    maxlength="100"
                                    placeholder="e.g. ACTION BASE"
                                    class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    data-testid="restock-exclusion-product-term"
                                    @keyup.enter="addExcludedProductTerm"
                                />
                                <button
                                    type="button"
                                    class="rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
                                    :disabled="
                                        savingExclusions ||
                                        exclusionProductTermDraft.trim().length < 2
                                    "
                                    data-testid="restock-add-product-exclusion"
                                    @click="addExcludedProductTerm"
                                >
                                    Always hide
                                </button>
                            </div>
                        </label>
                        <label class="flex min-w-64 flex-1 flex-col gap-1 text-xs text-slate-600">
                            Entire series
                            <div class="flex gap-2">
                                <select
                                    v-model="exclusionSeriesDraft"
                                    class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    data-testid="restock-exclusion-series"
                                >
                                    <option value="">Choose a series</option>
                                    <option
                                        v-for="series in availableExclusionSeries"
                                        :key="series"
                                        :value="series"
                                    >
                                        {{ series }}
                                    </option>
                                </select>
                                <button
                                    type="button"
                                    class="rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
                                    :disabled="savingExclusions || exclusionSeriesDraft === ''"
                                    data-testid="restock-add-series-exclusion"
                                    @click="addExcludedSeries"
                                >
                                    Always hide
                                </button>
                            </div>
                        </label>
                    </div>
                    <div
                        v-if="excludedSeries.length > 0 || excludedProductTerms.length > 0"
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <button
                            v-for="series in excludedSeries"
                            :key="`series-${series}`"
                            type="button"
                            class="rounded-full border border-indigo-200 bg-white px-2.5 py-1 text-xs text-indigo-700 hover:border-rose-300 hover:text-rose-700"
                            :disabled="savingExclusions"
                            :data-testid="`restock-remove-series-exclusion-${series}`"
                            :aria-label="`Remove automatic series exclusion ${series}`"
                            @click="removeExcludedSeries(series)"
                        >
                            Series: {{ series }} ×
                        </button>
                        <button
                            v-for="term in excludedProductTerms"
                            :key="`term-${term}`"
                            type="button"
                            class="rounded-full border border-indigo-200 bg-white px-2.5 py-1 text-xs text-indigo-700 hover:border-rose-300 hover:text-rose-700"
                            :disabled="savingExclusions"
                            :data-testid="`restock-remove-product-exclusion-${term}`"
                            :aria-label="`Remove automatic product-name exclusion ${term}`"
                            @click="removeExcludedProductTerm(term)"
                        >
                            Name contains: {{ term }} ×
                        </button>
                    </div>
                </div>
                <div
                    v-if="selectedNewSkuList.length > 0"
                    class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3"
                    data-testid="restock-new-bulk-bar"
                >
                    <span class="text-sm text-slate-600"
                        >{{ selectedNewSkuList.length }} selected</span
                    >
                    <button
                        type="button"
                        class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                        :disabled="bulkSaving"
                        data-testid="restock-bulk-include"
                        @click="openBulkInclude"
                    >
                        Bulk include
                    </button>
                    <button
                        type="button"
                        class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                        :disabled="bulkSaving"
                        data-testid="restock-bulk-later"
                        @click="bulkLaterSelected"
                    >
                        Bulk later
                    </button>
                    <button
                        type="button"
                        class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                        :disabled="bulkSaving"
                        data-testid="restock-bulk-dismiss"
                        @click="bulkDismissSelected"
                    >
                        Bulk dismiss
                    </button>
                </div>
            </div>
            <div class="max-h-[calc(100vh-10rem)] overflow-auto">
                <table
                    class="min-w-full divide-y divide-slate-200 text-sm"
                    data-testid="restock-new-table"
                >
                    <thead
                        class="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600 shadow-sm"
                    >
                        <tr>
                            <th class="px-3 py-2" data-testid="restock-new-bulk-column">
                                <label class="flex cursor-pointer flex-col items-center gap-1">
                                    <span>Bulk</span>
                                    <input
                                        type="checkbox"
                                        :checked="allVisibleNewSelected"
                                        aria-label="Select all visible new products for bulk actions"
                                        data-testid="restock-new-select-all"
                                        @change="
                                            toggleSelectAllNew(
                                                ($event.target as HTMLInputElement).checked,
                                            )
                                        "
                                    />
                                </label>
                            </th>
                            <th class="px-3 py-2" data-testid="restock-new-cart-column">
                                <label class="flex cursor-pointer flex-col items-center gap-1">
                                    <span>Cart</span>
                                    <input
                                        type="checkbox"
                                        :checked="allVisibleNewCartSelected"
                                        :disabled="cartEligibleNewRows.length === 0"
                                        aria-label="Select all visible new products for the PLAMOD cart"
                                        data-testid="restock-new-cart-select-all"
                                        @change="
                                            toggleSelectAllNewCart(
                                                ($event.target as HTMLInputElement).checked,
                                            )
                                        "
                                    />
                                </label>
                            </th>
                            <th class="px-3 py-2">Image</th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleNewSort('sku')"
                                >
                                    SKU
                                    <span class="text-[10px]">{{ newSortIndicator('sku') }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleNewSort('product_name')"
                                >
                                    Product
                                    <span class="text-[10px]">{{
                                        newSortIndicator('product_name')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleNewSort('series')"
                                >
                                    Series
                                    <span class="text-[10px]">{{
                                        newSortIndicator('series')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleNewSort('category')"
                                >
                                    Category
                                    <span class="text-[10px]">{{
                                        newSortIndicator('category')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-slate-900"
                                    @click="toggleNewSort('release_date')"
                                >
                                    Release
                                    <span class="text-[10px]">{{
                                        newSortIndicator('release_date')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">
                                <span class="inline-flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleNewSort('status')"
                                    >
                                        Status
                                        <span class="text-[10px]">{{
                                            newSortIndicator('status')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="NEW_STATUS_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleNewSort('order_qty')"
                                    >
                                        Order qty
                                        <span class="text-[10px]">{{
                                            newSortIndicator('order_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="NEW_ORDER_QTY_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <span class="inline-flex items-center justify-end gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleNewSort('planned_maintain_qty')"
                                    >
                                        Planned maintain
                                        <span class="text-[10px]">{{
                                            newSortIndicator('planned_maintain_qty')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="NEW_PLANNED_MAINTAIN_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2">
                                <span class="inline-flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:text-slate-900"
                                        @click="toggleNewSort('new_product_cost')"
                                    >
                                        New cost
                                        <span class="text-[10px]">{{
                                            newSortIndicator('new_product_cost')
                                        }}</span>
                                    </button>
                                    <ColumnHeaderHelp :label="NEW_NEW_COST_TOOLTIP" />
                                </span>
                            </th>
                            <th class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="inline-flex w-full items-center justify-end gap-1 hover:text-slate-900"
                                    @click="toggleNewSort('line_total')"
                                >
                                    Line total
                                    <span class="text-[10px]">{{
                                        newSortIndicator('line_total')
                                    }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in filteredNewRows" :key="row.sku" class="hover:bg-slate-50">
                            <td class="px-3 py-2">
                                <input
                                    v-model="selectedNewSkus[row.sku]"
                                    type="checkbox"
                                    :data-testid="`restock-new-select-${row.sku}`"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    v-if="isNewCartEligible(row)"
                                    v-model="selectedCartSkus[row.sku]"
                                    type="checkbox"
                                    :data-testid="`restock-new-cart-select-${row.sku}`"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <button
                                    v-if="row.image_url"
                                    type="button"
                                    class="block h-10 w-10 overflow-hidden rounded border border-slate-200 bg-white hover:ring-2 hover:ring-indigo-300"
                                    :data-testid="`restock-new-image-${row.sku}`"
                                    @click="openImageOverlay(row)"
                                >
                                    <img
                                        :src="row.image_url"
                                        :alt="row.product_name"
                                        class="h-full w-full object-contain"
                                    />
                                </button>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-mono text-xs">{{ row.sku }}</div>
                                <div v-if="row.barcode" class="text-[11px] text-slate-500">
                                    {{ row.barcode }}
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <a
                                    v-if="row.plamod_pdp_url"
                                    :href="row.plamod_pdp_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-700 hover:underline"
                                >
                                    {{ row.product_name }}
                                </a>
                                <span v-else>{{ row.product_name }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-600">{{ row.series ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ row.category ?? '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ releaseDateLabel(row) }}
                                <span
                                    v-if="row.is_recent_release"
                                    class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-indigo-700"
                                    :title="RECENT_RELEASE_TOOLTIP"
                                >
                                    Recent
                                </span>
                            </td>
                            <td class="px-3 py-2 capitalize">{{ row.status }}</td>
                            <td class="px-3 py-2 text-right">
                                <input
                                    v-if="row.status === 'included'"
                                    v-model="newOrderDrafts[row.sku]"
                                    type="number"
                                    min="0"
                                    class="w-16 rounded-md border border-slate-200 px-2 py-1 text-right text-sm tabular-nums"
                                    :data-testid="`restock-new-order-${row.sku}`"
                                    :disabled="newDecisionSavingSku === row.sku"
                                    @change="saveNewIncludedQtys(row.sku)"
                                />
                                <span v-else class="tabular-nums">{{ row.order_qty ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input
                                    v-if="row.status === 'included'"
                                    v-model="newMaintainDrafts[row.sku]"
                                    type="number"
                                    min="0"
                                    class="w-16 rounded-md border border-slate-200 px-2 py-1 text-right text-sm tabular-nums"
                                    :data-testid="`restock-new-maintain-${row.sku}`"
                                    :disabled="newDecisionSavingSku === row.sku"
                                    @change="saveNewIncludedQtys(row.sku)"
                                />
                                <span v-else class="tabular-nums">{{
                                    row.planned_maintain_qty ?? '—'
                                }}</span>
                            </td>
                            <td
                                class="px-3 py-2 font-semibold tabular-nums"
                                :class="
                                    row.price_missing
                                        ? 'rounded bg-amber-50 ring-1 ring-amber-300'
                                        : ''
                                "
                                :title="row.price_missing ? NEW_LANDED_MISSING_TOOLTIP : ''"
                            >
                                {{ formatProductPrice(row.new_landed_cost) }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">
                                {{ formatLineTotal(row.line_total) }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-if="row.status !== 'included'"
                                        type="button"
                                        class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                                        @click="openInclude(row)"
                                    >
                                        Include
                                    </button>
                                    <button
                                        v-if="row.status === 'undecided'"
                                        type="button"
                                        class="rounded border border-violet-200 bg-violet-50 px-2 py-1 text-xs text-violet-900 hover:bg-violet-100"
                                        :data-testid="`restock-new-later-${row.sku}`"
                                        @click="laterSku(row.sku)"
                                    >
                                        Later
                                    </button>
                                    <button
                                        v-if="row.status === 'included'"
                                        type="button"
                                        class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                                        :data-testid="`restock-new-exclude-${row.sku}`"
                                        @click="excludeIncluded(row.sku)"
                                    >
                                        Exclude
                                    </button>
                                    <button
                                        v-if="row.status !== 'dismissed'"
                                        type="button"
                                        class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                                        @click="dismissSku(row.sku)"
                                    >
                                        Dismiss
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-if="
                                !loading &&
                                filteredNewRows.length === 0 &&
                                (proposal?.new_products?.length ?? 0) > 0
                            "
                        >
                            <td colspan="13" class="px-3 py-6 text-center text-slate-500">
                                No rows match your search or filters.
                            </td>
                        </tr>
                        <tr v-if="!loading && (proposal?.new_products?.length ?? 0) === 0">
                            <td colspan="13" class="px-3 py-6 text-center text-slate-500">
                                No new PLAMOD SKUs in view.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="includeDraft"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            data-testid="restock-include-dialog"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl">
                <h3 class="text-sm font-semibold text-slate-900">Include {{ includeDraft.sku }}</h3>
                <div class="mt-3 space-y-3">
                    <label class="block text-sm">
                        Order qty
                        <input
                            v-model="includeDraft.orderQty"
                            type="number"
                            min="0"
                            class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5"
                        />
                    </label>
                    <label class="block text-sm">
                        Planned maintain qty
                        <input
                            v-model="includeDraft.maintainQty"
                            type="number"
                            min="0"
                            class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5"
                        />
                    </label>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-sm"
                        @click="includeDraft = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        :disabled="includeSaving"
                        data-testid="restock-include-submit"
                        @click="submitInclude"
                    >
                        Save
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="bulkIncludeDraft"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            data-testid="restock-bulk-include-dialog"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl">
                <h3 class="text-sm font-semibold text-slate-900">
                    Include {{ bulkIncludeDraft.skus.length }} SKU(s)
                </h3>
                <div class="mt-3 space-y-3">
                    <label class="block text-sm">
                        Order qty
                        <input
                            v-model="bulkIncludeDraft.orderQty"
                            type="number"
                            min="0"
                            class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5"
                        />
                    </label>
                    <label class="block text-sm">
                        Planned maintain qty
                        <input
                            v-model="bulkIncludeDraft.maintainQty"
                            type="number"
                            min="0"
                            class="mt-1 w-full rounded-md border border-slate-200 px-2 py-1.5"
                        />
                    </label>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-sm"
                        @click="bulkIncludeDraft = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        :disabled="bulkSaving"
                        data-testid="restock-bulk-include-submit"
                        @click="submitBulkInclude"
                    >
                        Save
                    </button>
                </div>
            </div>
        </div>

        <PlamodRestockImageOverlay
            v-if="imageOverlay"
            :image-url="imageOverlay.imageUrl"
            :alt="imageOverlay.alt"
            @close="imageOverlay = null"
        />
    </div>
</template>

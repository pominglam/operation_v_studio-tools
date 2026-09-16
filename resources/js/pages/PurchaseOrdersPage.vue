<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import PoHistoryRow from '../components/purchaseOrders/PoHistoryRow.vue';
import PoImportPreviewDialog, {
    type PoImportPreview,
} from '../components/purchaseOrders/PoImportPreviewDialog.vue';
import PoCombinedPaymentDialog, {
    type PoCombinedPaymentPreview,
    type PoCombinedPaymentValues,
} from '../components/purchaseOrders/PoCombinedPaymentDialog.vue';
import { api } from '../lib/api';
import { clearPageState, loadPageState, savePageState } from '../lib/pageState';
import { isPmBrokerVendor } from '../composables/purchaseOrders/pmBrokerVendor';
import { useShipmentTrackingResolution } from '../composables/useShipmentTrackingResolution';
import type { PurchaseOrderListRow } from '../types/purchaseOrderList';

type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type ImportResult = {
    purchase_order_uuid: string;
    items: number;
    lots: number;
    shipping_per_unit: string | null;
};

type FilterOptions = {
    data: {
        vendors: string[];
    };
};

const { resolutionFor, isTrackingPending, resolveTrackingNumbers } =
    useShipmentTrackingResolution();

const DEFAULT_VENDOR_OPTIONS = [
    'Plamod',
    'Dspiae',
    'Stedi',
    'Other/multi',
    'Gaahleri',
    'MSMN',
    'PM',
    'JS',
] as const;
const DEFAULT_SELECTED_STATUSES: PurchaseOrderListRow['status'][] = [
    'draft',
    'ordered',
    'shipped',
    'received',
];
const STATE_KEY = 'purchase-orders:history-filters:v2';
type PurchaseOrderListTab = 'history' | 'import';
const activeTab = ref<PurchaseOrderListTab>('history');

const loading = ref(false);
const error = ref<string | null>(null);
const pos = ref<PurchaseOrderListRow[]>([]);
const meta = ref<Paginated<PurchaseOrderListRow>['meta'] | null>(null);
const hydrating = ref(true);

type PurchaseOrderSortBy =
    | 'id'
    | 'status'
    | 'shipment'
    | 'created'
    | 'ordered'
    | 'estimated_arrival'
    | 'received'
    | 'on_shelves'
    | 'vendor'
    | 'items'
    | 'product_total'
    | 'shipping_total'
    | 'surcharge_total'
    | 'total';
type HistorySortColumn = { key: PurchaseOrderSortBy; label: string; align?: 'right' };
const HISTORY_SORT_COLUMNS: HistorySortColumn[] = [
    { key: 'id', label: 'ID' },
    { key: 'status', label: 'Status' },
    { key: 'shipment', label: 'Shipment' },
    { key: 'created', label: 'Created' },
    { key: 'ordered', label: 'Ordered' },
    { key: 'estimated_arrival', label: 'Estimated arrival' },
    { key: 'received', label: 'Received' },
    { key: 'on_shelves', label: 'On shelves' },
    { key: 'vendor', label: 'Vendor' },
    { key: 'items', label: 'Items', align: 'right' },
    { key: 'product_total', label: 'Product total', align: 'right' },
    { key: 'shipping_total', label: 'Shipping total', align: 'right' },
    { key: 'surcharge_total', label: 'Surcharge total', align: 'right' },
    { key: 'total', label: 'Total', align: 'right' },
];
const sortBy = ref<PurchaseOrderSortBy>('ordered');
const sortDir = ref<'asc' | 'desc'>('desc');
const selectedVendors = ref<string[]>([]);
const selectedStatuses = ref<PurchaseOrderListRow['status'][]>([...DEFAULT_SELECTED_STATUSES]);

const file = ref<File | null>(null);
const importing = ref(false);
const previewOpen = ref(false);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
const importPreview = ref<PoImportPreview | null>(null);
const importError = ref<string | null>(null);
const importIssues = ref<Array<Record<string, unknown>> | null>(null);
const importResult = ref<ImportResult | null>(null);

const deleteTarget = ref<PurchaseOrderListRow | null>(null);
const deletingId = ref<string | null>(null);
const deleteError = ref<string | null>(null);
const selectedPoIds = ref<Set<string>>(new Set());
const combinedPaymentOpen = ref(false);
const combinedPaymentBusy = ref(false);
const combinedPaymentError = ref<string | null>(null);
const combinedPaymentPreview = ref<PoCombinedPaymentPreview | null>(null);
const combinedPaymentSuccess = ref<string | null>(null);

const deleteDialogOpen = computed(() => deleteTarget.value !== null);
const selectedPurchaseOrders = computed(() =>
    pos.value.filter((po) => selectedPoIds.value.has(po.id)),
);
const allVisibleSelected = computed(
    () => pos.value.length > 0 && pos.value.every((po) => selectedPoIds.value.has(po.id)),
);
const deleteDialogMessage = computed(() => {
    const po = deleteTarget.value;
    if (!po) return '';
    const lineCount = po.counts.items;
    const vendorLabel = po.vendor.trim() !== '' ? po.vendor : 'Unknown vendor';
    const supplierLabel =
        po.supplier_order_id && po.supplier_order_id.trim() !== ''
            ? ` (supplier order ${po.supplier_order_id.trim()})`
            : '';
    const linesLabel = lineCount > 0 ? ` and its ${lineCount} line item(s)` : '';
    const base = `Delete ${vendorLabel}${supplierLabel}${linesLabel}? This cannot be undone. POs with received inventory or FIFO lots cannot be deleted.`;
    if (deleteError.value) {
        return `${base}\n\n${deleteError.value}`;
    }
    return base;
});

const vendors = ref<string[]>([]);
const vendor = ref<string>('');
const supplierOrderId = ref<string>('');
const orderedDate = ref<string>('');
const shippedDate = ref<string>('');
const estimatedArrivalDate = ref<string>('');
const receivedDate = ref<string>('');
const fullyOnShelvesDate = ref<string>('');
const productTotal = ref<string>('');
const productTotalIncludesFees = ref(false);
const shippingTotal = ref<string>('');
const surchargeTotal = ref<string>('');
const notes = ref<string>('');
const shipmentMethod = ref<'' | 'air' | 'sea'>('');

watch(vendor, (next) => {
    if (isPlamodVendor(next) && shipmentMethod.value === '') {
        shipmentMethod.value = 'air';
    }
});

const hasImportResult = computed(() => importResult.value !== null);

const vendorFilterOptions = computed<MultiSelectOption[]>(() =>
    vendors.value.map((v) => ({ value: v, label: v })),
);
const statusFilterOptions = computed<MultiSelectOption[]>(() => [
    { value: 'draft', label: 'Draft' },
    { value: 'ordered', label: 'Ordered' },
    { value: 'shipped', label: 'Shipped' },
    { value: 'received', label: 'Received' },
    { value: 'on_shelves', label: 'On shelves' },
]);

watch(
    selectedVendors,
    () => {
        if (hydrating.value) return;
        void loadHistory();
    },
    { deep: true },
);
watch(
    selectedStatuses,
    () => {
        if (hydrating.value) return;
        void loadHistory();
    },
    { deep: true },
);
watch(
    [selectedVendors, selectedStatuses, activeTab],
    () => {
        if (hydrating.value) return;
        persistListPageState();
    },
    { deep: true },
);

function persistListPageState(): void {
    savePageState(STATE_KEY, {
        selectedVendors: selectedVendors.value,
        selectedStatuses: selectedStatuses.value,
        activeTab: activeTab.value,
    });
}

function setActiveTab(tab: PurchaseOrderListTab): void {
    activeTab.value = tab;
}

function tabButtonClass(tab: PurchaseOrderListTab): string {
    const base =
        'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors';
    return activeTab.value === tab
        ? `${base} bg-white text-slate-900 shadow-sm`
        : `${base} text-slate-600 hover:bg-white/60 hover:text-slate-900`;
}

function mergeVendorOptions(next: string[]): void {
    const normalized = new Map<string, string>();
    for (const raw of [...vendors.value, ...next]) {
        const value = String(raw ?? '').trim();
        if (value === '') continue;
        const key = value.toLowerCase();
        if (!normalized.has(key)) {
            normalized.set(key, value);
        }
    }

    const merged = Array.from(normalized.values()).sort((a, b) => a.localeCompare(b));
    vendors.value = merged;
    // Keep vendor editable/blank by default so datalist can show all options.
    // (A prefilled value causes native datalist UIs to appear single-option filtered.)
    if (vendor.value.trim() !== '' && !merged.includes(vendor.value)) {
        vendor.value = '';
    }
}

async function loadVendors(): Promise<void> {
    let hadSuccess = false;
    try {
        const res = await api.get<FilterOptions>('/api/v1/purchase-orders/filter-options');
        mergeVendorOptions(res.data.data.vendors ?? []);
        hadSuccess = true;
    } catch {
        // fallback to products API
    }

    try {
        const res = await api.get<FilterOptions>('/api/v1/products/filter-options');
        mergeVendorOptions(res.data.data.vendors ?? []);
        hadSuccess = true;
    } catch {
        if (!hadSuccess) {
            vendors.value = [];
        }
    }
}

function isPlamodVendor(value: string): boolean {
    return value.trim().toLowerCase() === 'plamod';
}

async function sniffVendorFromCsv(f: File): Promise<string | null> {
    try {
        const head = (await f.slice(0, 4096).text()).toUpperCase();
        if (head.includes('DSPIAE')) return 'Dspiae';
        if (head.includes('STEDI') || /\bMS-[A-Z0-9]+\b/.test(head)) return 'Stedi';
        if (head.includes('QTY FILLED') && head.includes('ORDER ID')) return 'Plamod';
        return null;
    } catch {
        return null;
    }
}

function buildImportFormData(): FormData {
    const fd = new FormData();
    if (!file.value) {
        throw new Error('Missing file');
    }
    fd.append('file', file.value);
    fd.append('vendor', vendor.value);
    if (supplierOrderId.value.trim() !== '') {
        fd.append('supplier_order_id', supplierOrderId.value.trim());
    }
    if (orderedDate.value) fd.append('ordered_date', orderedDate.value);
    if (shippedDate.value) fd.append('shipped_date', shippedDate.value);
    if (estimatedArrivalDate.value) fd.append('estimated_arrival_date', estimatedArrivalDate.value);
    if (receivedDate.value) fd.append('received_date', receivedDate.value);
    if (fullyOnShelvesDate.value) fd.append('fully_on_shelves_date', fullyOnShelvesDate.value);
    if (productTotal.value) fd.append('product_total', productTotal.value);
    if (productTotalIncludesFees.value) {
        fd.append('product_total_includes_fees', '1');
    }
    if (shippingTotal.value && !productTotalIncludesFees.value) {
        fd.append('shipping_total', shippingTotal.value);
    }
    if (surchargeTotal.value) fd.append('surcharge_total', surchargeTotal.value);
    if (notes.value) fd.append('notes', notes.value);
    if (shipmentMethod.value) fd.append('shipment_method', shipmentMethod.value);

    return fd;
}

async function loadHistory(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const params: Record<string, unknown> = {
            per_page: 50,
            sort_by: sortBy.value,
            sort_dir: sortDir.value,
        };
        if (selectedVendors.value.length > 0) {
            params.vendors = selectedVendors.value;
        }
        if (selectedStatuses.value.length > 0) {
            params.statuses = selectedStatuses.value;
        }
        const res = await api.get<Paginated<PurchaseOrderListRow>>('/api/v1/purchase-orders', {
            params,
        });
        pos.value = res.data.data;
        meta.value = res.data.meta;
        const visibleIds = new Set(pos.value.map((po) => po.id));
        selectedPoIds.value = new Set([...selectedPoIds.value].filter((id) => visibleIds.has(id)));
        mergeVendorOptions(
            pos.value.map((x) => String(x.vendor ?? '').trim()).filter((x) => x !== ''),
        );
        void resolveTrackingNumbers(visibleTrackingNumbers());
    } catch {
        error.value = 'Failed to load purchase orders.';
    } finally {
        loading.value = false;
    }
}

function visibleTrackingNumbers(): string[] {
    const unique = new Map<string, string>();
    for (const po of pos.value) {
        for (const trackingNumber of po.shipment_tracking_numbers ?? []) {
            const trimmed = trackingNumber.trim();
            if (trimmed !== '') unique.set(trimmed.replace(/\s+/g, '').toUpperCase(), trimmed);
        }
    }
    return [...unique.values()];
}

function toggleSort(key: PurchaseOrderSortBy): void {
    if (sortBy.value !== key) {
        sortBy.value = key;
        sortDir.value = 'desc';
        void loadHistory();
        return;
    }
    sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    void loadHistory();
}

function sortIndicator(key: PurchaseOrderSortBy): string {
    if (sortBy.value !== key) return '';
    return sortDir.value === 'asc' ? ' ▲' : ' ▼';
}

function historySortHeaderClass(column: HistorySortColumn): string {
    return column.align === 'right' ? 'px-2 py-2 text-right' : 'px-2 py-2';
}

function applyUpdatedPo(updated: PurchaseOrderListRow): void {
    pos.value = pos.value.map((po) => (po.id === updated.id ? updated : po));
}

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    if (file.value) {
        void (async () => {
            const detected = await sniffVendorFromCsv(file.value!);
            if (detected) vendor.value = detected;
        })();
    }
}

function closeImportPreview(): void {
    previewOpen.value = false;
    previewLoading.value = false;
    previewError.value = null;
    importPreview.value = null;
}

async function loadImportPreview(): Promise<void> {
    previewError.value = null;
    importPreview.value = null;
    previewLoading.value = true;
    previewOpen.value = true;

    try {
        const fd = buildImportFormData();
        const res = await api.post<PoImportPreview>('/api/v1/purchase-orders/import/preview', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        importPreview.value = res.data;
    } catch (e: unknown) {
        const anyErr = e as any;
        previewError.value =
            anyErr?.response?.data?.message ??
            'Preview failed. Check the file format and try again.';
    } finally {
        previewLoading.value = false;
    }
}

async function executeImport(): Promise<void> {
    importing.value = true;
    importError.value = null;
    importIssues.value = null;
    importResult.value = null;

    try {
        const fd = buildImportFormData();
        const res = await api.post<ImportResult>('/api/v1/purchase-orders/import', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        importResult.value = res.data;
        closeImportPreview();
        await loadHistory();
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        const apiIssues: unknown = anyErr?.response?.data?.issues;
        importError.value = apiMessage ?? 'Import failed. Check the file format and try again.';
        importIssues.value = Array.isArray(apiIssues)
            ? (apiIssues as Array<Record<string, unknown>>)
            : null;
        previewError.value = importError.value;
    } finally {
        importing.value = false;
    }
}

async function runImport(): Promise<void> {
    importError.value = null;
    importIssues.value = null;
    importResult.value = null;

    if (!file.value) {
        importError.value = 'Please choose a CSV or XLSX file.';
        return;
    }

    if (isPmBrokerVendor(vendor.value)) {
        await loadImportPreview();
        return;
    }

    importing.value = true;
    try {
        await executeImport();
    } finally {
        importing.value = false;
    }
}

async function confirmImportPreview(): Promise<void> {
    await executeImport();
}

function resetHistoryFilters(): void {
    clearPageState(STATE_KEY);
    selectedVendors.value = [];
    selectedStatuses.value = [...DEFAULT_SELECTED_STATUSES];
    sortBy.value = 'ordered';
    sortDir.value = 'desc';
    persistListPageState();
    void loadHistory();
}

function togglePoSelection(id: string): void {
    const next = new Set(selectedPoIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selectedPoIds.value = next;
    combinedPaymentSuccess.value = null;
}

function toggleAllVisible(): void {
    if (allVisibleSelected.value) {
        selectedPoIds.value = new Set();
        return;
    }
    selectedPoIds.value = new Set(pos.value.map((po) => po.id));
}

function clearPoSelection(): void {
    selectedPoIds.value = new Set();
}

function openCombinedPaymentDialog(): void {
    if (selectedPurchaseOrders.value.length < 2) return;
    combinedPaymentError.value = null;
    combinedPaymentPreview.value = null;
    combinedPaymentOpen.value = true;
}

function closeCombinedPaymentDialog(): void {
    if (combinedPaymentBusy.value) return;
    combinedPaymentOpen.value = false;
    combinedPaymentError.value = null;
    combinedPaymentPreview.value = null;
}

function combinedPaymentPayload(values: PoCombinedPaymentValues): {
    purchase_order_ids: string[];
    total_paid_cad: string;
    includes_shipping: boolean;
    product_paid_cad?: string;
    shipping_paid_cad?: string;
    allocations?: PoCombinedPaymentValues['allocations'];
} {
    return {
        purchase_order_ids: selectedPurchaseOrders.value.map((po) => po.id),
        total_paid_cad: values.total_paid_cad,
        includes_shipping: values.includes_shipping,
        ...(values.product_paid_cad
            ? {
                  product_paid_cad: values.product_paid_cad,
                  shipping_paid_cad: values.shipping_paid_cad,
              }
            : {}),
        ...(values.allocations ? { allocations: values.allocations } : {}),
    };
}

async function previewCombinedPayment(values: PoCombinedPaymentValues): Promise<void> {
    combinedPaymentBusy.value = true;
    combinedPaymentError.value = null;
    combinedPaymentPreview.value = null;
    try {
        const response = await api.post<{ data: PoCombinedPaymentPreview }>(
            '/api/v1/purchase-orders/combined-payments/preview',
            combinedPaymentPayload(values),
        );
        combinedPaymentPreview.value = response.data.data;
    } catch (exception: unknown) {
        const apiError = exception as { response?: { data?: { message?: string } } };
        combinedPaymentError.value =
            apiError.response?.data?.message ?? 'Unable to preview the combined payment.';
    } finally {
        combinedPaymentBusy.value = false;
    }
}

async function recordCombinedPayment(values: PoCombinedPaymentValues): Promise<void> {
    combinedPaymentBusy.value = true;
    combinedPaymentError.value = null;
    try {
        await api.post<{ data: PoCombinedPaymentPreview }>(
            '/api/v1/purchase-orders/combined-payments',
            combinedPaymentPayload(values),
        );
        combinedPaymentOpen.value = false;
        combinedPaymentPreview.value = null;
        combinedPaymentSuccess.value = `Combined payment recorded across ${selectedPurchaseOrders.value.length} POs.`;
        clearPoSelection();
        await loadHistory();
    } catch (exception: unknown) {
        const apiError = exception as { response?: { data?: { message?: string } } };
        combinedPaymentError.value =
            apiError.response?.data?.message ?? 'Unable to record the combined payment.';
    } finally {
        combinedPaymentBusy.value = false;
    }
}

function openDeleteDialog(po: PurchaseOrderListRow): void {
    deleteError.value = null;
    deleteTarget.value = po;
}

function cancelDeleteDialog(): void {
    if (deletingId.value !== null) return;
    deleteTarget.value = null;
    deleteError.value = null;
}

async function confirmDeletePo(): Promise<void> {
    const po = deleteTarget.value;
    if (!po) return;

    deletingId.value = po.id;
    deleteError.value = null;

    try {
        const res = await api.delete<{ message?: string }>(`/api/v1/purchase-orders/${po.id}`, {
            validateStatus: () => true,
        });
        if (res.status !== 200) {
            deleteError.value = res.data?.message ?? 'Failed to delete purchase order.';
            return;
        }
        deleteTarget.value = null;
        await loadHistory();
    } catch {
        deleteError.value = 'Failed to delete purchase order.';
    } finally {
        deletingId.value = null;
    }
}

onMounted(() => {
    const saved = loadPageState<{
        selectedVendors?: string[];
        selectedStatuses?: PurchaseOrderListRow['status'][];
        activeTab?: PurchaseOrderListTab;
    }>(STATE_KEY);
    if (saved) {
        if (Array.isArray(saved.selectedVendors)) selectedVendors.value = saved.selectedVendors;
        if (Array.isArray(saved.selectedStatuses)) selectedStatuses.value = saved.selectedStatuses;
        if (saved.activeTab === 'history' || saved.activeTab === 'import') {
            activeTab.value = saved.activeTab;
        }
    }

    hydrating.value = false;
    mergeVendorOptions([...DEFAULT_VENDOR_OPTIONS]);
    void loadVendors();
    void loadHistory();
});
</script>

<template>
    <main class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-slate-900">Purchase Orders</h1>
            <p class="mt-1 text-sm text-slate-600">
                Import a vendor PO CSV or PM broker XLSX, enter shipping total, and track FIFO lots
                for landed costing.
            </p>
        </div>

        <nav
            class="mb-4 flex gap-1 rounded-lg border border-slate-200 bg-slate-100 p-1"
            role="tablist"
            aria-label="Purchase order views"
            data-testid="po-list-tabs"
        >
            <button
                id="po-tab-history"
                type="button"
                role="tab"
                :aria-selected="activeTab === 'history'"
                aria-controls="po-panel-history"
                :class="tabButtonClass('history')"
                data-testid="po-tab-history"
                @click="setActiveTab('history')"
            >
                List
            </button>
            <button
                id="po-tab-import"
                type="button"
                role="tab"
                :aria-selected="activeTab === 'import'"
                aria-controls="po-panel-import"
                :class="tabButtonClass('import')"
                data-testid="po-tab-import"
                @click="setActiveTab('import')"
            >
                Create / Import
            </button>
        </nav>

        <section
            v-show="activeTab === 'import'"
            id="po-panel-import"
            class="rounded-lg border border-slate-200 bg-white p-4"
            role="tabpanel"
            aria-labelledby="po-tab-import"
            data-testid="po-panel-import"
        >
            <h2 class="text-sm font-semibold text-slate-900">Create / Import</h2>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6">
                <div class="lg:col-span-2">
                    <label class="text-xs font-medium text-slate-700">Vendor</label>
                    <input
                        v-model="vendor"
                        list="vendor-options"
                        type="text"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="Select or type vendor…"
                    />
                    <datalist id="vendor-options">
                        <option v-for="v in vendors" :key="v" :value="v" />
                    </datalist>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Supplier order ID</label>
                    <input
                        v-model="supplierOrderId"
                        type="text"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                        placeholder="Optional"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Shipment</label>
                    <select
                        v-model="shipmentMethod"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                    >
                        <option value="">—</option>
                        <option value="air">Air</option>
                        <option value="sea">Sea</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Ordered</label>
                    <input
                        v-model="orderedDate"
                        type="date"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Shipped</label>
                    <input
                        v-model="shippedDate"
                        type="date"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Received</label>
                    <input
                        v-model="receivedDate"
                        type="date"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Estimated arrival</label>
                    <input
                        v-model="estimatedArrivalDate"
                        type="date"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">On shelves</label>
                    <input
                        v-model="fullyOnShelvesDate"
                        type="date"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6 lg:items-end">
                <div>
                    <label class="text-xs font-medium text-slate-700">
                        {{ productTotalIncludesFees ? 'Total paid (CAD)' : 'Product total (CAD)' }}
                    </label>
                    <input
                        v-model="productTotal"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        :placeholder="productTotalIncludesFees ? 'Product + shipping' : '0.00'"
                    />
                    <label
                        v-if="isPmBrokerVendor(vendor)"
                        class="mt-2 flex items-start gap-2 text-xs text-slate-600"
                    >
                        <input
                            v-model="productTotalIncludesFees"
                            type="checkbox"
                            class="mt-0.5 rounded border-slate-300"
                        />
                        <span>Total includes product and shipping (split using invoice HKD)</span>
                    </label>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Shipping total</label>
                    <input
                        v-model="shippingTotal"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm disabled:bg-slate-50 disabled:text-slate-500"
                        placeholder="0.00"
                        :disabled="productTotalIncludesFees"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Surcharge total</label>
                    <input
                        v-model="surchargeTotal"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="0.00"
                    />
                </div>
                <div class="lg:col-span-3">
                    <label class="text-xs font-medium text-slate-700">Notes</label>
                    <input
                        v-model="notes"
                        type="text"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="Optional"
                    />
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6 lg:items-end">
                <div class="lg:col-span-5">
                    <label class="text-xs font-medium text-slate-700">CSV / XLSX file</label>
                    <input
                        type="file"
                        accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        @change="onFileChange"
                    />
                </div>
                <div class="lg:col-span-1">
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="importing || previewLoading"
                        @click="runImport"
                    >
                        {{
                            importing
                                ? 'Importing…'
                                : previewLoading
                                  ? 'Previewing…'
                                  : isPmBrokerVendor(vendor)
                                    ? 'Preview import'
                                    : 'Import CSV'
                        }}
                    </button>
                </div>
            </div>

            <p v-if="importError" class="mt-3 text-sm text-red-700">{{ importError }}</p>

            <div
                v-if="importIssues && importIssues.length"
                class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                <div class="font-medium">Import blocked. Fix these issues, then retry:</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li v-for="(issue, idx) in importIssues" :key="idx">
                        <code class="text-xs">{{ issue }}</code>
                    </li>
                </ul>
            </div>

            <div
                v-if="hasImportResult"
                class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800"
            >
                <div>
                    <span class="font-medium">PO:</span>
                    <a
                        class="underline underline-offset-2"
                        :href="`/purchase-orders/${importResult!.purchase_order_uuid}`"
                    >
                        {{ importResult!.purchase_order_uuid }}
                    </a>
                </div>
                <div class="mt-1 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-4">
                    <div><span class="font-medium">Items:</span> {{ importResult!.items }}</div>
                    <div><span class="font-medium">Lots:</span> {{ importResult!.lots }}</div>
                    <div>
                        <span class="font-medium">Ship/unit:</span>
                        {{ importResult!.shipping_per_unit ?? '—' }}
                    </div>
                </div>
            </div>
        </section>

        <section
            v-show="activeTab === 'history'"
            id="po-panel-history"
            class="rounded-lg border border-slate-200 bg-white p-4"
            role="tabpanel"
            aria-labelledby="po-tab-history"
            data-testid="po-panel-history"
        >
            <div class="flex items-center justify-end">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                        :disabled="loading"
                        @click="resetHistoryFilters"
                    >
                        Reset filters
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                        :disabled="loading"
                        @click="loadHistory"
                    >
                        Refresh
                    </button>
                </div>
            </div>

            <div class="mt-3 max-w-md">
                <MultiSelectFilter
                    v-model="selectedVendors"
                    label="Vendors"
                    :options="vendorFilterOptions"
                    placeholder="All vendors"
                    test-id="po-history-vendor-filter"
                />
            </div>
            <div class="mt-3 max-w-md">
                <MultiSelectFilter
                    v-model="selectedStatuses"
                    label="Status"
                    :options="statusFilterOptions"
                    placeholder="All statuses"
                    test-id="po-history-status-filter"
                />
            </div>

            <div
                v-if="selectedPoIds.size > 0"
                class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2"
            >
                <div class="text-sm text-slate-700">
                    <span class="font-semibold text-slate-900">{{ selectedPoIds.size }}</span>
                    PO(s) selected
                    <span v-if="selectedPoIds.size < 2" class="ml-1 text-xs text-amber-700">
                        Select at least two POs.
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100"
                        @click="clearPoSelection"
                    >
                        Clear
                    </button>
                    <button
                        type="button"
                        data-testid="combined-payment-open"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="selectedPoIds.size < 2"
                        @click="openCombinedPaymentDialog"
                    >
                        Record combined payment
                    </button>
                </div>
            </div>

            <p v-if="combinedPaymentSuccess" class="mt-3 text-sm text-emerald-700">
                {{ combinedPaymentSuccess }}
            </p>
            <p v-if="error" class="mt-3 text-sm text-red-700">{{ error }}</p>
            <p v-else-if="loading" class="mt-3 text-sm text-slate-600">Loading…</p>

            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-slate-600">
                        <tr>
                            <th class="px-2 py-2">
                                <input
                                    type="checkbox"
                                    data-testid="po-history-select-all"
                                    aria-label="Select all visible purchase orders"
                                    :checked="allVisibleSelected"
                                    @change="toggleAllVisible"
                                />
                            </th>
                            <th
                                v-for="column in HISTORY_SORT_COLUMNS"
                                :key="column.key"
                                :class="historySortHeaderClass(column)"
                            >
                                <button
                                    type="button"
                                    class="hover:underline"
                                    :data-testid="`po-history-sort-${column.key}`"
                                    @click="toggleSort(column.key)"
                                >
                                    {{ column.label }}{{ sortIndicator(column.key) }}
                                </button>
                            </th>
                            <th class="px-2 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-800">
                        <PoHistoryRow
                            v-for="po in pos"
                            :key="po.id"
                            :po="po"
                            :selected="selectedPoIds.has(po.id)"
                            :deleting="deletingId === po.id"
                            :resolution-for="resolutionFor"
                            :is-tracking-pending="isTrackingPending"
                            @toggle-select="togglePoSelection(po.id)"
                            @delete="openDeleteDialog(po)"
                            @updated="applyUpdatedPo"
                        />
                    </tbody>
                </table>

                <p v-if="meta && meta.total === 0" class="mt-3 text-sm text-slate-600">
                    No purchase orders yet.
                </p>
            </div>
        </section>

        <PoImportPreviewDialog
            :open="previewOpen"
            :busy="importing || previewLoading"
            :preview="importPreview"
            :error="previewError"
            @cancel="closeImportPreview"
            @confirm="confirmImportPreview"
        />

        <PoCombinedPaymentDialog
            :open="combinedPaymentOpen"
            :busy="combinedPaymentBusy"
            :selected-count="selectedPoIds.size"
            :preview="combinedPaymentPreview"
            :error="combinedPaymentError"
            @cancel="closeCombinedPaymentDialog"
            @preview="previewCombinedPayment"
            @confirm="recordCombinedPayment"
        />

        <ConfirmDialog
            :open="deleteDialogOpen"
            title="Delete purchase order"
            :message="deleteDialogMessage"
            confirm-text="Delete"
            variant="danger"
            :busy="deletingId !== null"
            @cancel="cancelDeleteDialog"
            @confirm="confirmDeletePo"
        />
    </main>
</template>

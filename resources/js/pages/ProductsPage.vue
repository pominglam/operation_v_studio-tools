<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../lib/api';
import { clearPageState, loadPageState, savePageState } from '../lib/pageState';
import AddProductForm, {
    type CreateProductPayload,
} from '../components/products/AddProductForm.vue';
import ImportProductsCard from '../components/products/ImportProductsCard.vue';
import ImportInventoryCard from '../components/products/ImportInventoryCard.vue';
import ImportHandlesCard from '../components/products/ImportHandlesCard.vue';
import ShopifyContentExportCard from '../components/products/ShopifyContentExportCard.vue';
import ProductsTable, {
    type ProductRow,
    type ProductSortKey,
    type BulkUpdateProductChanges,
    type UpdateProductPayload,
} from '../components/products/ProductsTable.vue';
import type { ProductsBulkExportType } from '../components/products/BulkExportDialog.vue';
import PlamodDrawer from '../components/products/PlamodDrawer.vue';
import ProductPoLinesDrawer from '../components/products/ProductPoLinesDrawer.vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import PaginationControls from '../components/ui/PaginationControls.vue';

type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

const loading = ref(false);
const error = ref<string | null>(null);
const products = ref<ProductRow[]>([]);
const meta = ref<Paginated<ProductRow>['meta'] | null>(null);

const route = useRoute();
const router = useRouter();

const creating = ref(false);
const createError = ref<string | null>(null);
const createMessage = ref<string | null>(null);

type ProductsToolTab = 'list' | 'add' | 'import' | 'export';
const activeTab = ref<ProductsToolTab>('list');
const exportFormat = ref<'shopify'>('shopify');
const missingSellingPriceLoading = ref(false);
const missingSellingPriceError = ref<string | null>(null);
const missingSellingPriceItems = ref<Array<{ id: string; sku: string; barcode: string | null; description: string }>>(
    [],
);

function tabFromHash(hash: string): ProductsToolTab | null {
    const key = hash.startsWith('#') ? hash.slice(1) : hash;
    const normalized = key.trim().toLowerCase();
    if (normalized === 'products' || normalized === 'list') return 'list';
    if (normalized === 'add') return 'add';
    if (normalized === 'import') return 'import';
    if (normalized === 'export') return 'export';
    return null;
}

function setActiveTab(next: ProductsToolTab): void {
    activeTab.value = next;
    const nextHash = next === 'list' ? '#products' : `#${next}`;
    if (route.hash !== nextHash) {
        void router.replace({ hash: nextHash });
    }
}

function downloadExport(): void {
    const params = new URLSearchParams();
    params.set('format', exportFormat.value);
    params.set('sort_by', sortBy.value);
    params.set('sort_dir', sortDir.value);

    window.location.assign(`/api/v1/products/export?${params.toString()}`);
}

function downloadMissingBarcodeCsv(): void {
    const params = new URLSearchParams();
    params.set('format', exportFormat.value);
    params.set('sort_by', sortBy.value);
    params.set('sort_dir', sortDir.value);
    window.location.assign(`/api/v1/products/export/missing-barcode?${params.toString()}`);
}

function downloadBarcodedProductsCsv(): void {
    window.location.assign('/api/v1/products/export/barcoded');
}

function parseFilenameFromContentDisposition(header: string | undefined): string | null {
    if (!header) return null;
    // content-disposition: attachment; filename="foo.csv"
    const m = /filename\*?=(?:UTF-8''|\"?)([^\";]+)\"?/i.exec(header);
    if (!m) return null;
    try {
        return decodeURIComponent(m[1]);
    } catch {
        return m[1];
    }
}

async function bulkExportSelected(ids: string[], exportType: ProductsBulkExportType): Promise<void> {
    if (exportType === 'shopify_content') {
        const res = await api.post<{
            download_url: string;
        }>(
            '/api/v1/products/exports/shopify-content/prepare',
            { ids },
            { validateStatus: () => true },
        );

        if (res.status !== 200) {
            throw new Error('export_failed');
        }

        const downloadUrl = res.data.download_url;
        if (!downloadUrl) {
            throw new Error('export_failed');
        }

        window.location.assign(downloadUrl);
        return;
    }

    const res = await api.post(
        '/api/v1/products/export/selected',
        {
            export_type: exportType,
            ids,
            sort_by: sortBy.value,
            sort_dir: sortDir.value,
        },
        {
            responseType: 'blob',
            validateStatus: () => true,
        },
    );

    if (res.status !== 200) {
        throw new Error('export_failed');
    }

    const header = (res.headers as Record<string, string | undefined>)['content-disposition'];
    const filename = parseFilenameFromContentDisposition(header) ?? `products-selected-${exportType}.csv`;

    const blob = res.data as Blob;
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
}

async function loadMissingSellingPrice(): Promise<void> {
    missingSellingPriceLoading.value = true;
    missingSellingPriceError.value = null;
    try {
        const res = await api.get<{ data: Array<{ id: string; sku: string; barcode: string | null; description: string }> }>(
            '/api/v1/products/export/missing-selling-price',
            {
                params: {
                    format: exportFormat.value,
                    sort_by: sortBy.value,
                    sort_dir: sortDir.value,
                },
            },
        );
        missingSellingPriceItems.value = res.data.data;
    } catch {
        missingSellingPriceError.value = 'Failed to load products missing selling price.';
    } finally {
        missingSellingPriceLoading.value = false;
    }
}

const search = ref('');
const perPage = ref(50);
const page = ref(1);
const sortBy = ref<ProductSortKey>('sku');
const sortDir = ref<'asc' | 'desc'>('asc');
const selectedTypes = ref<string[]>([]);
const selectedVendors = ref<string[]>([]);
const selectedMissing = ref<string[]>([]);

const missingOptions = ref<MultiSelectOption[]>([
    { value: 'ok', label: 'OK (complete)' },
    { value: 'pdp_images', label: 'PDP images' },
    { value: 'pdp_description', label: 'PDP description' },
    { value: 'selling_price', label: 'Selling price' },
    { value: 'barcode', label: 'Barcode' },
    { value: 'handle', label: 'Handle' },
]);

const syncMissingOpen = ref(false);
const syncMissingBusy = ref(false);
const syncMissingCount = ref<number | null>(null);
const syncMissingError = ref<string | null>(null);
const syncMissingMessage = ref<string | null>(null);

const typeOptions = ref<MultiSelectOption[]>([]);
const vendorOptions = ref<MultiSelectOption[]>([]);

const plamodDrawerOpen = ref(false);
const plamodDrawerProductId = ref<string | null>(null);
const plamodDrawerProductSku = ref<string | null>(null);
const plamodDrawerProductPrice = ref<string | null>(null);

const poLinesOpen = ref(false);
const poLinesProductId = ref<string | null>(null);
const poLinesProductSku = ref<string | null>(null);
const poLinesProductName = ref<string | null>(null);

function openPlamodDrawer(productId: string): void {
    plamodDrawerProductId.value = productId;
    const p = products.value.find((x) => x.id === productId) ?? null;
    plamodDrawerProductSku.value = p?.sku ?? null;
    plamodDrawerProductPrice.value = p?.selling_price ?? null;
    plamodDrawerOpen.value = true;
}

function closePlamodDrawer(): void {
    plamodDrawerOpen.value = false;
}

function openPoLinesDrawer(productId: string): void {
    poLinesProductId.value = productId;
    const p = products.value.find((x) => x.id === productId) ?? null;
    poLinesProductSku.value = p?.sku ?? null;
    poLinesProductName.value = p?.description ?? null;
    poLinesOpen.value = true;
}

function closePoLinesDrawer(): void {
    poLinesOpen.value = false;
}

const total = computed<number>(() => meta.value?.total ?? 0);
const currentPage = computed<number>(() => meta.value?.current_page ?? page.value);
const lastPage = computed<number>(() => meta.value?.last_page ?? 1);

let inFlightLoadKey: string | null = null;
let lastLoadedKey: string | null = null;
let lastLoadedAt = 0;

function buildLoadKey(): string {
    const types = [...selectedTypes.value].map((t) => t.trim()).filter(Boolean).sort();
    const vendors = [...selectedVendors.value].map((v) => v.trim()).filter(Boolean).sort();
    const missing = [...selectedMissing.value].map((m) => m.trim()).filter(Boolean).sort();
    return JSON.stringify({
        per_page: perPage.value,
        page: page.value,
        search: search.value.trim() || null,
        sort_by: sortBy.value,
        sort_dir: sortDir.value,
        types,
        vendors,
        missing,
    });
}

async function load(): Promise<void> {
    const key = buildLoadKey();
    // Prevent duplicate concurrent loads (common during initial hydration + batch polling).
    if (inFlightLoadKey === key) return;
    // Prevent rapid sequential duplicate loads for the same query (common during initial page load).
    const now = Date.now();
    if (lastLoadedKey === key && now - lastLoadedAt < 1500) return;
    inFlightLoadKey = key;

    loading.value = true;
    error.value = null;

    try {
        const res = await api.get<Paginated<ProductRow>>('/api/v1/products', {
            params: {
                per_page: perPage.value,
                page: page.value,
                search: search.value.trim() || undefined,
                sort_by: sortBy.value,
                sort_dir: sortDir.value,
                types: selectedTypes.value.length > 0 ? selectedTypes.value : undefined,
                vendors: selectedVendors.value.length > 0 ? selectedVendors.value : undefined,
                missing:
                    selectedMissing.value.length > 0
                        ? selectedMissing.value
                        : undefined,
            },
        });
        products.value = res.data.data;
        meta.value = res.data.meta;
    } catch (e: unknown) {
        error.value = 'Failed to load products.';
    } finally {
        loading.value = false;
        if (inFlightLoadKey === key) inFlightLoadKey = null;
        lastLoadedKey = key;
        lastLoadedAt = Date.now();
    }
}

async function loadFilterOptions(): Promise<void> {
    try {
        const res = await api.get<{ data: { types: string[]; vendors?: string[] } }>(
            '/api/v1/products/filter-options',
        );
        typeOptions.value = res.data.data.types.map((t) => ({ value: t, label: t }));
        vendorOptions.value = (res.data.data.vendors ?? []).map((v) => ({ value: v, label: v }));
    } catch {
        // ignore; filter dropdown will just be empty
    }
}

async function create(payload: CreateProductPayload): Promise<void> {
    creating.value = true;
    createError.value = null;
    createMessage.value = null;

    try {
        await api.post('/api/v1/products', payload);
        createMessage.value = 'Product created.';
        page.value = 1;
        await load();
        await loadFilterOptions();
    } catch (e: unknown) {
        createError.value = 'Failed to create product (check SKU uniqueness and required fields).';
    } finally {
        creating.value = false;
    }
}

async function bulkDelete(ids: string[]): Promise<number> {
    const res = await api.post<{ deleted: number }>('/api/v1/products/bulk-delete', { ids });
    await loadFilterOptions();
    return res.data.deleted;
}

async function bulkUpdate(ids: string[], changes: BulkUpdateProductChanges): Promise<number> {
    const res = await api.post<{ updated: number }>('/api/v1/products/bulk-update', { ids, changes });
    await loadFilterOptions();
    return res.data.updated;
}

async function bulkRenamePlamodAssets(ids: string[]): Promise<number> {
    const res = await api.post<{ ok: boolean; renamed_assets: number }>('/api/v1/products/bulk/plamod-assets/rename', { ids });
    return res.data.renamed_assets ?? 0;
}

async function updateProduct(id: string, payload: UpdateProductPayload): Promise<void> {
    await api.patch(`/api/v1/products/${id}`, payload);
    await loadFilterOptions();
}

type JobBatchStatus = {
    id: string;
    name: string;
    total_jobs: number;
    pending_jobs: number;
    processed_jobs: number;
    failed_jobs: number;
    progress_percent: number;
    finished_at: string | null;
    cancelled_at: string | null;
};

const syncBatchId = ref<string | null>(null);
const syncBatchStatus = ref<JobBatchStatus | null>(null);
let syncBatchPollTimer: number | null = null;

const STATE_KEY = 'page_state:products';
const hydrating = ref(true);

function stopSyncBatchPoll(): void {
    if (syncBatchPollTimer !== null) {
        window.clearInterval(syncBatchPollTimer);
        syncBatchPollTimer = null;
    }
}

async function pollSyncBatchOnce(): Promise<void> {
    if (!syncBatchId.value) return;
    try {
        const res = await api.get<{ ok: boolean; data: JobBatchStatus }>(
            `/api/v1/job-batches/${syncBatchId.value}`,
        );
        syncBatchStatus.value = res.data.data;
        if (res.data.data.finished_at || res.data.data.cancelled_at) {
            stopSyncBatchPoll();
            // Refresh products so missing-info badges/filters reflect the latest ingested content.
            void load();
        }
    } catch {
        // ignore transient polling failures
    }
}

async function startSyncBatchPoll(): Promise<void> {
    stopSyncBatchPoll();
    await pollSyncBatchOnce();
    const s = syncBatchStatus.value;
    if (s?.finished_at || s?.cancelled_at) {
        return;
    }
    syncBatchPollTimer = window.setInterval(() => void pollSyncBatchOnce(), 2000);
}

async function requestSyncMissing(): Promise<void> {
    syncMissingError.value = null;
    syncMissingMessage.value = null;
    syncMissingCount.value = null;
    syncMissingBusy.value = true;

    try {
        const res = await api.post<{ ok: boolean; queued: number; dry_run: boolean; batch_id?: string | null }>(
            '/api/v1/products/sync-missing-info',
            {
                search: search.value.trim() || undefined,
                types: selectedTypes.value.length > 0 ? selectedTypes.value : undefined,
                vendors: selectedVendors.value.length > 0 ? selectedVendors.value : undefined,
                missing:
                    selectedMissing.value.length > 0 && !selectedMissing.value.includes('ok')
                        ? selectedMissing.value
                        : undefined,
                dry_run: true,
            },
        );

        syncMissingCount.value = res.data.queued;
        syncMissingOpen.value = true;
    } catch {
        syncMissingError.value = 'Failed to calculate missing info count.';
    } finally {
        syncMissingBusy.value = false;
    }
}

async function confirmSyncMissing(): Promise<void> {
    syncMissingBusy.value = true;
    syncMissingError.value = null;
    syncMissingMessage.value = null;

    try {
        const res = await api.post<{ ok: boolean; queued: number; dry_run: boolean; batch_id?: string | null }>(
            '/api/v1/products/sync-missing-info',
            {
                search: search.value.trim() || undefined,
                types: selectedTypes.value.length > 0 ? selectedTypes.value : undefined,
                vendors: selectedVendors.value.length > 0 ? selectedVendors.value : undefined,
                missing:
                    selectedMissing.value.length > 0 && !selectedMissing.value.includes('ok')
                        ? selectedMissing.value
                        : undefined,
                dry_run: false,
            },
        );
        const batchId = res.data.batch_id ?? null;
        syncBatchId.value = batchId;
        syncBatchStatus.value = null;
        if (batchId) {
            try {
                window.localStorage.setItem('last_sync_batch_id', batchId);
            } catch {
                // ignore
            }
            void startSyncBatchPoll();
        }
        syncMissingMessage.value = `Queued ${res.data.queued} product(s) for sync.`;
        syncMissingOpen.value = false;
    } catch {
        syncMissingError.value = 'Failed to queue sync jobs.';
    } finally {
        syncMissingBusy.value = false;
    }
}

function onSortChange(next: ProductSortKey): void {
    if (sortBy.value === next) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }

    sortBy.value = next;
    sortDir.value = 'asc';
}

function onPageChange(next: number): void {
    page.value = Math.max(1, next);
}

let searchTimer: number | null = null;
watch([search, perPage, selectedTypes, selectedVendors, selectedMissing, sortBy, sortDir], () => {
    if (hydrating.value) return;
    page.value = 1;
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void load(), 250);
});

watch(selectedMissing, () => {
    if (hydrating.value) return;
    // Make "OK (complete)" mutually exclusive with other flags.
    if (selectedMissing.value.includes('ok') && selectedMissing.value.length > 1) {
        selectedMissing.value = ['ok'];
        return;
    }
}, { deep: true });

watch(page, () => {
    if (hydrating.value) return;
    void load();
});

onMounted(() => {
    const saved = loadPageState<{
        activeTab?: ProductsToolTab;
        search?: string;
        perPage?: number;
        page?: number;
        sortBy?: ProductSortKey;
        sortDir?: 'asc' | 'desc';
        selectedTypes?: string[];
        selectedVendors?: string[];
        selectedMissing?: string[];
    }>(STATE_KEY);

    if (saved) {
        if (saved.activeTab) activeTab.value = saved.activeTab;
        if (typeof saved.search === 'string') search.value = saved.search;
        if (typeof saved.perPage === 'number') perPage.value = saved.perPage;
        if (typeof saved.page === 'number') page.value = saved.page;
        if (saved.sortBy) sortBy.value = saved.sortBy;
        if (saved.sortDir) sortDir.value = saved.sortDir;
        if (Array.isArray(saved.selectedTypes)) selectedTypes.value = saved.selectedTypes;
        if (Array.isArray(saved.selectedVendors)) selectedVendors.value = saved.selectedVendors;
        if (Array.isArray(saved.selectedMissing)) selectedMissing.value = saved.selectedMissing;
    }

    hydrating.value = false;

    void loadFilterOptions();
    void load();

    try {
        const last = window.localStorage.getItem('last_sync_batch_id');
        if (last && !syncBatchId.value) {
            syncBatchId.value = last;
            void startSyncBatchPoll();
        }
    } catch {
        // ignore
    }
});

watch(activeTab, () => {
    if (activeTab.value !== 'list') {
        stopSyncBatchPoll();
    }
});

watch(
    () => route.hash,
    (hash) => {
        if (hydrating.value) return;
        const tab = tabFromHash(hash);
        if (!tab) return;
        activeTab.value = tab;
        void nextTick(() => {
            if (tab === 'import') {
                document.getElementById('import')?.scrollIntoView({ block: 'start' });
            }
        });
    },
    { immediate: true },
);

watch(
    [activeTab, search, perPage, page, selectedTypes, selectedVendors, selectedMissing, sortBy, sortDir],
    () => {
        if (hydrating.value) return;
        savePageState(STATE_KEY, {
            activeTab: activeTab.value,
            search: search.value,
            perPage: perPage.value,
            page: page.value,
            sortBy: sortBy.value,
            sortDir: sortDir.value,
            selectedTypes: selectedTypes.value,
            selectedVendors: selectedVendors.value,
            selectedMissing: selectedMissing.value,
        });
    },
    { deep: true },
);

function resetListState(): void {
    clearPageState(STATE_KEY);
    search.value = '';
    perPage.value = 50;
    page.value = 1;
    sortBy.value = 'sku';
    sortDir.value = 'asc';
    selectedTypes.value = [];
    selectedVendors.value = [];
    selectedMissing.value = [];
    void load();
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Products</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Products currently stored in the database.
                </p>
            </div>

            <button
                class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                type="button"
                :disabled="loading"
                @click="load"
            >
                Refresh
            </button>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 bg-slate-50 px-3 pt-2">
                <div
                    class="flex flex-wrap items-end gap-2"
                    role="tablist"
                    aria-label="Product tools"
                >
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'list'"
                        :class="
                            activeTab === 'list'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="setActiveTab('list')"
                    >
                        Products
                    </button>
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'add'"
                        :class="
                            activeTab === 'add'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="setActiveTab('add')"
                    >
                        Add
                    </button>
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'import'"
                        :class="
                            activeTab === 'import'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="setActiveTab('import')"
                    >
                        Import
                    </button>
                    <button
                        class="-mb-px rounded-t-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        role="tab"
                        type="button"
                        :aria-selected="activeTab === 'export'"
                        :class="
                            activeTab === 'export'
                                ? 'border-slate-200 border-b-white bg-white text-slate-900'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        "
                        @click="setActiveTab('export')"
                    >
                        Export
                    </button>
                </div>
            </div>

            <div class="p-4">
                <div v-show="activeTab === 'list'" class="space-y-4">
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-4 md:items-end">
                            <div class="md:col-span-2">
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                                    >Search</label
                                >
                                <input
                                    v-model="search"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                                    type="text"
                                    placeholder="Search SKU / barcode / name…"
                                />
                            </div>

                            <MultiSelectFilter
                                v-model="selectedTypes"
                                label="Type"
                                :options="typeOptions"
                                placeholder="All types"
                            />

                            <MultiSelectFilter
                                v-model="selectedVendors"
                                label="Vendor"
                                :options="vendorOptions"
                                placeholder="All vendors"
                            />

                            <MultiSelectFilter
                                v-model="selectedMissing"
                                label="Missing info"
                                :options="missingOptions"
                                placeholder="Any"
                            />

                            <div>
                                <label
                                    class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                                    >Per page</label
                                >
                                <select
                                    v-model.number="perPage"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                >
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                    <option :value="100">100</option>
                                    <option :value="200">200</option>
                                    <option :value="500">500</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-col gap-2 text-sm text-slate-600 md:flex-row md:items-center md:justify-between">
                            <div>
                                Showing
                                <span class="font-medium text-slate-900">{{ products.length }}</span> of
                                <span class="font-medium text-slate-900">{{ total }}</span>
                            </div>

                            <div class="flex flex-col gap-2 md:flex-row md:items-center">
                                <div v-if="syncMissingError" class="text-rose-700">{{ syncMissingError }}</div>
                                <div v-if="syncMissingMessage" class="text-emerald-700">{{ syncMissingMessage }}</div>
                                <a
                                    class="text-xs font-semibold text-slate-900 underline"
                                    href="/sync-progress"
                                >
                                    Sync progress
                                </a>
                                <button
                                    class="text-xs font-semibold text-slate-900 underline"
                                    type="button"
                                    :disabled="loading"
                                    @click="resetListState"
                                >
                                    Reset
                                </button>
                                <div
                                    v-if="syncBatchId && syncBatchStatus"
                                    class="flex items-center gap-2 text-slate-700"
                                >
                                    <div class="w-40 overflow-hidden rounded-full bg-slate-200">
                                        <div
                                            class="h-2 bg-slate-900"
                                            :style="{ width: `${syncBatchStatus.progress_percent}%` }"
                                        />
                                    </div>
                                    <div class="tabular-nums text-xs">
                                        {{ syncBatchStatus.progress_percent }}% ({{ syncBatchStatus.processed_jobs }}/{{
                                            syncBatchStatus.total_jobs
                                        }})
                                        <span v-if="syncBatchStatus.failed_jobs > 0" class="text-rose-700">
                                            · failed {{ syncBatchStatus.failed_jobs }}
                                        </span>
                                    </div>
                                    <a
                                        class="text-xs font-semibold text-slate-900 underline"
                                        :href="`/sync-progress?batch_id=${encodeURIComponent(syncBatchId)}`"
                                    >
                                        Open
                                    </a>
                                </div>
                                <button
                                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                                    type="button"
                                    :disabled="loading || syncMissingBusy"
                                    @click="requestSyncMissing"
                                >
                                    {{ syncMissingBusy ? 'Checking…' : 'Sync missing PDP info' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <ProductsTable
                        :loading="loading"
                        :products="products"
                        :sort-by="sortBy"
                        :sort-dir="sortDir"
                        :on-sort-change="onSortChange"
                        :on-refresh="load"
                        :on-bulk-delete="bulkDelete"
                        :on-bulk-update="bulkUpdate"
                        :on-bulk-rename-plamod-assets="bulkRenamePlamodAssets"
                        :on-bulk-export-selected="bulkExportSelected"
                        :on-update="updateProduct"
                        :on-open-plamod="openPlamodDrawer"
                        :on-open-po-lines="openPoLinesDrawer"
                        :vendor-options="vendorOptions.map((v) => v.value)"
                    />

                    <PaginationControls
                        :current-page="currentPage"
                        :last-page="lastPage"
                        :total="total"
                        :on-change="onPageChange"
                    />
                </div>

                <AddProductForm
                    v-show="activeTab === 'add'"
                    :busy="creating"
                    :error="createError"
                    :message="createMessage"
                    :on-create="create"
                    :vendor-options="vendorOptions.map((v) => v.value)"
                    :embedded="true"
                />
                <ImportProductsCard v-show="activeTab === 'import'" :embedded="true" />
                <div v-show="activeTab === 'import'" class="mt-4">
                    <ImportInventoryCard :embedded="true" />
                </div>
                <div v-show="activeTab === 'import'" class="mt-4">
                    <ImportHandlesCard :embedded="true" />
                </div>

                <div v-show="activeTab === 'export'" class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="text-sm font-semibold text-slate-900">Shopify import</div>
                            <div class="mt-1 text-sm text-slate-600">
                                Generate a Shopify CSV for products that are ready to publish. Uses the product’s
                                “Published on Shopify” field.
                            </div>

                            <div class="mt-3 max-w-sm">
                                <label class="block text-xs font-semibold tracking-wide text-slate-600">Format</label>
                                <select
                                    v-model="exportFormat"
                                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                >
                                    <option value="shopify">Shopify</option>
                                </select>
                            </div>

                            <div class="mt-3 text-xs text-slate-600">
                                Note: Export includes only products with a selling price set.
                            </div>

                            <button
                                class="mt-3 inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                                type="button"
                                @click="downloadExport"
                            >
                                Export for Shopify (CSV)
                            </button>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="text-sm font-semibold text-slate-900">Operations</div>
                            <div class="mt-1 text-sm text-slate-600">
                                Review current sellable inventory. Exports only products with barcodes; sorted by Type
                                then SKU.
                            </div>

                            <button
                                class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                                type="button"
                                @click="downloadBarcodedProductsCsv"
                            >
                                Export current inventory (CSV)
                            </button>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="text-sm font-semibold text-slate-900">Data cleanup</div>
                            <div class="mt-1 text-sm text-slate-600">
                                Find products that need fixes before publishing or scanning at fulfillment.
                            </div>

                            <button
                                class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                                type="button"
                                @click="downloadMissingBarcodeCsv"
                            >
                                Export missing barcodes (CSV)
                            </button>
                        </div>
                    </div>

                    <ShopifyContentExportCard />

                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">
                                    Products missing selling price
                                </div>
                                <div class="mt-1 text-sm text-slate-600">
                                    These are excluded from the Shopify export.
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                                :disabled="missingSellingPriceLoading"
                                @click="loadMissingSellingPrice"
                            >
                                {{ missingSellingPriceLoading ? 'Loading…' : 'Refresh list' }}
                            </button>
                        </div>

                        <div
                            v-if="missingSellingPriceError"
                            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                        >
                            {{ missingSellingPriceError }}
                        </div>

                        <div v-else class="mt-3 text-sm text-slate-700">
                            <div class="text-slate-600">
                                {{ missingSellingPriceItems.length }} product(s)
                            </div>

                            <div
                                v-if="missingSellingPriceItems.length > 0"
                                class="mt-3 overflow-x-auto rounded-md border border-slate-200"
                            >
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50">
                                        <tr
                                            class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600"
                                        >
                                            <th class="px-3 py-2">Product</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr
                                            v-for="p in missingSellingPriceItems"
                                            :key="p.id"
                                            class="hover:bg-slate-50"
                                        >
                                            <td class="px-3 py-2">
                                                <div
                                                    class="max-w-[40rem] truncate font-medium text-slate-900"
                                                    :title="p.description"
                                                >
                                                    {{ p.description }}
                                                </div>
                                                <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-slate-600">
                                                    <span class="font-mono">{{ p.sku }}</span>
                                                    <span class="font-mono">{{ p.barcode ?? '—' }}</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <PlamodDrawer
            :open="plamodDrawerOpen"
            :product-id="plamodDrawerProductId"
            :product-sku="plamodDrawerProductSku"
            :product-price="plamodDrawerProductPrice"
            :on-close="closePlamodDrawer"
        />

        <ProductPoLinesDrawer
            :open="poLinesOpen"
            :product-id="poLinesProductId"
            :product-sku="poLinesProductSku"
            :product-name="poLinesProductName"
            @close="closePoLinesDrawer"
        />

        <ConfirmDialog
            :open="syncMissingOpen"
            title="Sync missing PDP info"
            :message="`Queue sync jobs for ${syncMissingCount ?? 0} product(s) missing PDP info?`"
            confirm-text="Queue sync"
            :busy="syncMissingBusy"
            @cancel="syncMissingOpen = false"
            @confirm="confirmSyncMissing"
        />
    </section>
</template>

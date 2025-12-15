<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api } from '../lib/api';
import { formatLocalDateTime } from '../lib/datetime';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import PaginationControls from '../components/ui/PaginationControls.vue';

type Quote = {
    site_key: string;
    site_name: string;
    status: 'found' | 'not_found' | 'error';
    availability: 'in_stock' | 'sold_out' | null;
    currency: string;
    price: string | null;
    original_price: string | null;
    product_url: string | null;
    error_message: string | null;
    fetched_at: string;
};

type ProductResearch = {
    id: string;
    sku: string;
    barcode: string | null;
    description: string;
    price_researched_at: string | null;
    expired: boolean;
    cost: string | null;
    selling_price: string | null;
    quotes: Quote[];
};

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
const running = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const items = ref<ProductResearch[]>([]);
const activeRunId = ref<string | null>(null);
const polling = ref(false);
const destroyed = ref(false);
const runStatus = ref<{
    id: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    total_products: number;
    processed_products: number;
    refreshed_products: number;
    skipped_fresh_products: number;
    total_sites: number;
    processed_sites: number;
    quotes_written: number;
    started_at: string | null;
    finished_at: string | null;
    error_message: string | null;
} | null>(null);

const deleting = ref<{ productId: string; siteKey: string } | null>(null);
const recrawlingProductId = ref<string | null>(null);
const reporting = ref<{
    productId: string;
    sku: string;
    siteKey: string;
    siteName: string;
} | null>(null);
const reportNote = ref('');
const reportSaving = ref(false);

const isRunActive = computed<boolean>(() => {
    return runStatus.value?.status === 'queued' || runStatus.value?.status === 'running';
});

const isBusy = computed<boolean>(() => loading.value || running.value || isRunActive.value);

type ResearchSortKey =
    | 'sku'
    | 'description'
    | 'price_researched_at'
    | 'cost'
    | 'selling_price'
    | 'multiplier';

const search = ref('');
const perPage = ref(50);
const page = ref(1);
const sortBy = ref<ResearchSortKey>('price_researched_at');
const sortDir = ref<'asc' | 'desc'>('desc');

const freshnessOptions: MultiSelectOption[] = [
    { value: 'fresh', label: 'Fresh' },
    { value: 'expired', label: 'Expired' },
];
const freshness = ref<string[]>([]);

const allSites = [
    { key: 'argama_hobby', name: 'Argama Hobby' },
    { key: 'panda_hobby', name: 'Panda Hobby' },
    { key: 'canadian_gundam', name: 'Canadian Gundam' },
    { key: 'hobby_bee', name: 'Hobby Bee' },
    { key: 'hobby_wholesale', name: 'HobbyWholesale' },
    { key: 'meeplemart', name: 'Meeplemart' },
    { key: 'hobby_sense', name: 'Hobby Sense' },
    { key: 'gundam_hangar', name: 'Gundam Hangar' },
];

const disabledSiteKeys = ref<string[]>([]);
const sites = computed(() => {
    const disabled = new Set(disabledSiteKeys.value);
    return allSites.filter((s) => !disabled.has(s.key));
});

const quoteSiteOptions = computed<MultiSelectOption[]>(() => {
    return sites.value.map((s) => ({ value: s.key, label: s.name }));
});
const quoteSites = ref<string[]>([]);

const sellingPrice = ref<'any' | 'set' | 'missing'>('any');

const productTypes = ref<string[]>([]);
const productTypeOptions = computed<MultiSelectOption[]>(() => {
    return productTypes.value.map((t) => ({ value: t, label: t }));
});
const types = ref<string[]>([]);

const meta = ref<Paginated<ProductResearch>['meta'] | null>(null);
const total = computed<number>(() => meta.value?.total ?? 0);
const currentPage = computed<number>(() => meta.value?.current_page ?? page.value);
const lastPage = computed<number>(() => meta.value?.last_page ?? 1);

function quoteFor(product: ProductResearch, siteKey: string): Quote | null {
    return product.quotes.find((q) => q.site_key === siteKey) ?? null;
}

async function deleteQuote(productId: string, siteKey: string): Promise<void> {
    deleting.value = { productId, siteKey };
    error.value = null;
    message.value = null;

    try {
        await api.delete(`/api/v1/price-research/products/${productId}/quotes/${siteKey}`);
        message.value = 'Quote deleted.';
        await load();
    } catch {
        error.value = 'Failed to delete quote.';
    } finally {
        deleting.value = null;
    }
}

function openReportDialog(p: ProductResearch, siteKey: string): void {
    const site = sites.find((s) => s.key === siteKey);
    if (!site) return;

    reporting.value = {
        productId: p.id,
        sku: p.sku,
        siteKey,
        siteName: site.name,
    };
    reportNote.value = '';
}

function closeReportDialog(): void {
    reporting.value = null;
    reportNote.value = '';
}

async function submitReport(): Promise<void> {
    if (!reporting.value) return;

    reportSaving.value = true;
    error.value = null;
    message.value = null;

    try {
        const res = await api.post<{ message?: string }>(
            '/api/v1/price-research/reports',
            {
                product_id: reporting.value.productId,
                site_key: reporting.value.siteKey,
                note: reportNote.value.trim() || null,
                run_id: runStatus.value?.id ?? null,
            },
            { validateStatus: () => true },
        );

        if (res.status !== 201) {
            error.value = res.data?.message ?? 'Failed to save report.';
            return;
        }

        message.value = 'Report saved.';
        closeReportDialog();
    } catch {
        error.value = 'Failed to save report.';
    } finally {
        reportSaving.value = false;
    }
}

function parseMoney(value: string | null): number | null {
    if (!value) return null;
    const cleaned = value.replace(/[^0-9.-]/g, '');
    if (!cleaned) return null;
    const n = Number.parseFloat(cleaned);
    return Number.isFinite(n) ? n : null;
}

function formatMoney(value: number | null): string | null {
    if (value === null) return null;
    return value.toFixed(2);
}

function averagePriceOnline(p: ProductResearch): string | null {
    const disabled = new Set(disabledSiteKeys.value);
    const nums = p.quotes
        .filter((q) => q.status === 'found' && !disabled.has(q.site_key))
        .map((q) => parseMoney(q.price))
        .filter((n): n is number => n !== null);

    if (nums.length === 0) return null;
    const avg = nums.reduce((a, b) => a + b, 0) / nums.length;
    return formatMoney(avg);
}

function hasWeirdPriceSpread(p: ProductResearch): boolean {
    const nums = p.quotes
        .filter((q) => q.status === 'found')
        .map((q) => parseMoney(q.price))
        .filter((n): n is number => n !== null);

    if (nums.length < 2) return false;

    const min = Math.min(...nums);
    const max = Math.max(...nums);
    if (min <= 0) return false;

    return (max - min) / min > 0.3;
}

function costTimes(p: ProductResearch, factor: number): string | null {
    const n = parseMoney(p.cost);
    if (n === null) return null;
    return formatMoney(n * factor);
}

function marginMultiplier(p: ProductResearch): string | null {
    const cost = parseMoney(p.cost);
    const selling = parseMoney(p.selling_price);
    if (cost === null || selling === null) return null;
    if (cost <= 0) return null;
    return (selling / cost).toFixed(2);
}

function siteHeaderLine1(name: string): string {
    const trimmed = name.trim();
    const idx = trimmed.indexOf(' ');
    if (idx === -1) return trimmed;
    return trimmed.slice(0, idx);
}

function siteHeaderLine2(name: string): string | null {
    const trimmed = name.trim();
    const idx = trimmed.indexOf(' ');
    if (idx === -1) return null;
    const rest = trimmed.slice(idx + 1).trim();
    return rest === '' ? null : rest;
}

function buildProductsUrl(): string {
    const params = new URLSearchParams();
    params.set('per_page', String(perPage.value));
    params.set('page', String(page.value));
    params.set('sort_by', sortBy.value);
    params.set('sort_dir', sortDir.value);
    const s = search.value.trim();
    if (s) params.set('search', s);

    if (sellingPrice.value !== 'any') {
        params.set('selling_price', sellingPrice.value);
    }

    for (const v of freshness.value) params.append('freshness[]', v);
    for (const v of types.value) params.append('types[]', v);
    for (const v of quoteSites.value) params.append('quote_sites[]', v);

    return `/api/v1/price-research/products?${params.toString()}`;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    message.value = null;

    try {
        // Use fetch directly here to avoid any adapter/proxy issues during local dev.
        const ctrl = new AbortController();
        const t = window.setTimeout(() => ctrl.abort(), 15000);
        try {
            const r = await fetch(buildProductsUrl(), { signal: ctrl.signal });
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            const json = (await r.json()) as Paginated<ProductResearch>;
            items.value = json.data;
            meta.value = json.meta;
        } finally {
            window.clearTimeout(t);
        }
    } catch (e: unknown) {
        error.value = 'Failed to load price research results.';
    } finally {
        loading.value = false;
    }
}

async function loadLatestRun(): Promise<void> {
    try {
        const r = await fetch('/api/v1/price-research/runs/latest');
        if (!r.ok) return;
        const json = (await r.json()) as { data: typeof runStatus.value };
        runStatus.value = json.data;
        activeRunId.value = json.data?.id ?? null;

        if (json.data && (json.data.status === 'queued' || json.data.status === 'running')) {
            void pollRun(json.data.id);
        }
    } catch {
        // ignore
    }
}

async function loadProductFilterOptions(): Promise<void> {
    try {
        const r = await fetch('/api/v1/products/filter-options');
        if (!r.ok) return;
        const json = (await r.json()) as { data?: { types?: string[] } };
        productTypes.value = (json.data?.types ?? []).filter(
            (t) => typeof t === 'string' && t.trim() !== '',
        );
    } catch {
        // ignore
    }
}

async function pollRun(id: string): Promise<void> {
    if (polling.value) return;
    polling.value = true;
    running.value = true;

    try {
        // Important: keep this function alive while polling. The prior implementation used setTimeout
        // recursion which caused pollRun() to return early, leading to overlapping poll loops and a UI
        // that looks like it's constantly refreshing.
        while (!destroyed.value) {
            try {
                const r = await fetch(`/api/v1/price-research/runs/${id}`);
                if (r.ok) {
                    const json = (await r.json()) as { data: NonNullable<typeof runStatus.value> };
                    runStatus.value = json.data;

                    if (json.data.status !== 'queued' && json.data.status !== 'running') {
                        await load();
                        break;
                    }
                }
            } catch {
                // ignore; we'll retry after delay
            }

            await new Promise((resolve) => window.setTimeout(resolve, 1500));
        }
    } finally {
        running.value = false;
        polling.value = false;
    }
}

async function run(force: boolean): Promise<void> {
    running.value = true;
    error.value = null;
    message.value = null;

    try {
        const res = await api.post(
            '/api/v1/price-research/run',
            { force },
            { validateStatus: () => true },
        );

        const runId = res.data?.run_id as string | undefined;
        if (runId) {
            activeRunId.value = runId;
            await pollRun(runId);
        }

        if (res.status === 202) {
            message.value = 'Queued price research job. Showing live status below…';
            return;
        }

        message.value = `Processed ${res.data.data.processed}. Refreshed ${res.data.data.refreshed}. Skipped fresh ${res.data.data.skipped_fresh}.`;
        await load();
    } catch (e: unknown) {
        error.value = 'Failed to run price research.';
    } finally {
        running.value = false;
    }
}

const savingSellingPrice = ref<string | null>(null);
const editingSellingPriceId = ref<string | null>(null);
const sellingPriceDrafts = reactive<Record<string, string>>({});

function startSellingPriceEdit(productId: string, current: string | null): void {
    editingSellingPriceId.value = productId;
    if (sellingPriceDrafts[productId] === undefined) {
        sellingPriceDrafts[productId] = current ?? '';
    }
}

function updateSellingPriceDraft(productId: string, value: string): void {
    sellingPriceDrafts[productId] = value;
}

function commitSellingPriceEdit(productId: string): void {
    editingSellingPriceId.value = null;
    const value = sellingPriceDrafts[productId] ?? '';
    delete sellingPriceDrafts[productId];
    void saveSellingPrice(productId, value);
}

async function saveSellingPrice(productId: string, value: string | null): Promise<void> {
    savingSellingPrice.value = productId;
    error.value = null;
    const row = items.value.find((p) => p.id === productId);
    const previous = row?.selling_price ?? null;

    try {
        const sellingPrice = value !== null && value.trim() !== '' ? value.trim() : null;
        if (row) {
            row.selling_price = sellingPrice;
        }

        const res = await api.put(
            `/api/v1/products/${productId}/selling-price`,
            { selling_price: sellingPrice },
            { validateStatus: () => true },
        );
        if (res.status < 200 || res.status >= 300) {
            if (row) {
                row.selling_price = previous;
            }
            error.value = 'Failed to save selling price.';
            return;
        }

        const saved = (res.data?.data?.selling_price as string | null | undefined) ?? null;
        if (row) {
            row.selling_price = saved;
        }
    } catch {
        if (row) {
            row.selling_price = previous;
        }
        error.value = 'Failed to save selling price.';
    } finally {
        savingSellingPrice.value = null;
    }
}

async function recrawlProduct(productId: string): Promise<void> {
    if (isBusy.value) return;
    running.value = true;
    recrawlingProductId.value = productId;
    error.value = null;
    message.value = null;

    try {
        const res = await api.post(
            '/api/v1/price-research/run',
            { force: true, ids: [productId] },
            { validateStatus: () => true },
        );

        const runId = res.data?.run_id as string | undefined;
        if (runId) {
            activeRunId.value = runId;
            message.value = 'Queued recrawl for this product. Showing live status below…';
            await pollRun(runId);
            return;
        }

        message.value = 'Recrawl started.';
        await load();
    } catch {
        error.value = 'Failed to recrawl product.';
    } finally {
        recrawlingProductId.value = null;
        running.value = false;
    }
}

async function loadPriceResearchFilterOptions(): Promise<void> {
    try {
        const r = await fetch('/api/v1/price-research/filter-options');
        if (!r.ok) return;
        const json = (await r.json()) as { data?: { disabled_site_keys?: string[] } };
        disabledSiteKeys.value = (json.data?.disabled_site_keys ?? []).filter(
            (t) => typeof t === 'string' && t.trim() !== '',
        );
    } catch {
        // ignore
    }
}

onMounted(() => {
    void load();
    void loadLatestRun();
    void loadProductFilterOptions();
    void loadPriceResearchFilterOptions();
});

onBeforeUnmount(() => {
    destroyed.value = true;
});

function onSortChange(next: ResearchSortKey): void {
    if (sortBy.value === next) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    sortBy.value = next;
    sortDir.value = next === 'price_researched_at' ? 'desc' : 'asc';
}

function sortIndicator(key: ResearchSortKey): string {
    if (sortBy.value !== key) return '';
    return sortDir.value === 'asc' ? ' ▲' : ' ▼';
}

function onPageChange(next: number): void {
    page.value = Math.max(1, next);
}

let searchTimer: number | null = null;
watch([search, perPage, sellingPrice, types, freshness, quoteSites, sortBy, sortDir], () => {
    page.value = 1;
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void load(), 250);
});
watch(page, () => void load());
watch(
    disabledSiteKeys,
    () => {
        const disabled = new Set(disabledSiteKeys.value);
        quoteSites.value = quoteSites.value.filter((k) => !disabled.has(k));
    },
    { deep: true },
);
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Price research</h1>
            </div>

            <div class="flex items-center gap-2">
                <RouterLink
                    to="/price-research/reports"
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                >
                    Reports
                </RouterLink>
                <button
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                    type="button"
                    :disabled="isBusy"
                    @click="run(false)"
                >
                    {{ isBusy ? 'Running…' : 'Run (expired only)' }}
                </button>
            </div>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>
        <div
            v-if="message"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        >
            {{ message }}
        </div>

        <div v-if="runStatus" class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="text-sm font-semibold text-slate-900">Run status</div>
                        <RouterLink
                            :to="`/price-research/runs/${runStatus.id}/logs`"
                            class="text-xs font-medium text-slate-700 underline hover:text-slate-900"
                        >
                            View logs
                        </RouterLink>
                    </div>
                    <div class="mt-1 text-sm text-slate-600">
                        <span class="font-medium">Status:</span> {{ runStatus.status }}
                        <span v-if="runStatus.started_at">
                            • <span class="font-medium">Started:</span>
                            {{ formatLocalDateTime(runStatus.started_at) }}</span
                        >
                        <span v-if="runStatus.finished_at">
                            • <span class="font-medium">Finished:</span>
                            {{ formatLocalDateTime(runStatus.finished_at) }}</span
                        >
                    </div>
                    <div v-if="runStatus.error_message" class="mt-2 text-sm text-rose-700">
                        {{ runStatus.error_message }}
                    </div>
                </div>
                <div class="text-right text-sm text-slate-600">
                    <div>
                        <span class="font-medium text-slate-900">{{
                            runStatus.processed_products
                        }}</span>
                        / {{ runStatus.total_products }} products
                    </div>
                    <div>
                        <span class="font-medium text-slate-900">{{
                            runStatus.processed_sites
                        }}</span>
                        / {{ runStatus.total_products * runStatus.total_sites }} site checks
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[260px] flex-[2_1_520px]">
                    <label
                        class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >Search</label
                    >
                    <input
                        v-model="search"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        type="text"
                        placeholder="Search SKU / barcode / description…"
                    />
                </div>

                <div class="min-w-[180px] flex-[1_1_220px]">
                    <MultiSelectFilter
                        v-model="freshness"
                        label="Status"
                        :options="freshnessOptions"
                        placeholder="Fresh + Expired"
                    />
                </div>

                <div class="min-w-[180px] flex-[1_1_220px]">
                    <MultiSelectFilter
                        v-model="quoteSites"
                        label="Site"
                        :options="quoteSiteOptions"
                        placeholder="All sites"
                    />
                </div>

                <div class="min-w-[180px] flex-[1_1_220px]">
                    <MultiSelectFilter
                        v-model="types"
                        label="Type"
                        :options="productTypeOptions"
                        placeholder="All types"
                    />
                </div>

                <div class="min-w-[180px] flex-[1_1_220px]">
                    <label
                        class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >Selling price</label
                    >
                    <select
                        v-model="sellingPrice"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                    >
                        <option value="any">All</option>
                        <option value="set">Has selling price</option>
                        <option value="missing">Missing selling price</option>
                    </select>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm text-slate-600">
                    Showing <span class="font-medium text-slate-900">{{ items.length }}</span> of
                    <span class="font-medium text-slate-900">{{ total }}</span>
                </div>

                <div class="min-w-[180px] flex-[0_0_220px]">
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
        </div>

        <div class="space-y-4">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr
                                class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600"
                            >
                                <th class="px-4 py-3">
                                    <button
                                        type="button"
                                        class="hover:underline"
                                        @click="onSortChange('sku')"
                                    >
                                        SKU{{ sortIndicator('sku') }}
                                    </button>
                                </th>
                                <th class="px-4 py-3">
                                    <div class="flex flex-col">
                                        <button
                                            type="button"
                                            class="text-left hover:underline"
                                            @click="onSortChange('description')"
                                        >
                                            PRODUCT NAME{{ sortIndicator('description') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-1 text-left text-xs font-semibold text-slate-500 hover:underline"
                                            @click="onSortChange('price_researched_at')"
                                        >
                                            Last updated{{ sortIndicator('price_researched_at') }}
                                        </button>
                                    </div>
                                </th>
                                <th class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="hover:underline"
                                        @click="onSortChange('cost')"
                                    >
                                        COST{{ sortIndicator('cost') }}
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right">1.5x</th>
                                <th class="px-4 py-3 text-right">
                                    <div class="flex flex-col items-end">
                                        <button
                                            type="button"
                                            class="hover:underline"
                                            @click="onSortChange('selling_price')"
                                        >
                                            PRICE{{ sortIndicator('selling_price') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="mt-1 text-xs font-semibold text-slate-500 hover:underline"
                                            @click="onSortChange('multiplier')"
                                        >
                                            Multiplier{{ sortIndicator('multiplier') }}
                                        </button>
                                    </div>
                                </th>
                                <th class="px-2.5 py-3 text-right">
                                    <div class="flex flex-col items-end leading-tight">
                                        <span>MARKET PRICE</span>
                                        <span class="mt-1 text-[10px] font-semibold text-slate-500"
                                            >(ONLINE)</span
                                        >
                                    </div>
                                </th>
                                <th
                                    v-for="s in sites"
                                    :key="s.key"
                                    class="px-2.5 py-2.5 text-center"
                                >
                                    <span
                                        class="mx-auto inline-block max-w-[110px] whitespace-normal break-words text-center leading-tight"
                                    >
                                        {{ siteHeaderLine1(s.name) }}
                                        <span v-if="siteHeaderLine2(s.name)" class="block">
                                            {{ siteHeaderLine2(s.name) }}
                                        </span>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="items.length === 0">
                                <td class="px-4 py-4 text-slate-600" :colspan="6 + sites.length">
                                    No products found.
                                </td>
                            </tr>

                            <tr v-for="p in items" :key="p.id" class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-900">{{ p.sku }}</td>
                                <td class="px-4 py-3 text-slate-700">
                                    <div class="font-medium text-slate-900">
                                        {{ p.description }}
                                    </div>
                                    <div class="mt-1 flex items-start justify-between gap-2">
                                        <div class="text-xs text-slate-500">
                                            {{ formatLocalDateTime(p.price_researched_at) }}
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="
                                                    p.expired
                                                        ? 'bg-amber-100 text-amber-800'
                                                        : 'bg-emerald-100 text-emerald-800'
                                                "
                                            >
                                                {{ p.expired ? 'Expired' : 'Fresh' }}
                                            </span>

                                            <button
                                                class="inline-flex h-6 w-6 items-center justify-center rounded text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40"
                                                type="button"
                                                title="Recrawl prices for this product"
                                                :aria-label="`Recrawl prices for SKU ${p.sku}`"
                                                :disabled="isBusy || recrawlingProductId === p.id"
                                                @click.stop="recrawlProduct(p.id)"
                                            >
                                                <span
                                                    v-if="recrawlingProductId === p.id"
                                                    class="text-xs"
                                                    >…</span
                                                >
                                                <svg
                                                    v-else
                                                    viewBox="0 0 24 24"
                                                    class="h-4 w-4"
                                                    aria-hidden="true"
                                                >
                                                    <path
                                                        fill="currentColor"
                                                        d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 0 1-8.9 3.1l-1.46 1.46A7 7 0 0 0 19 13c0-3.87-3.13-7-7-7Zm-5 7a5 5 0 0 1 8.9-3.1l1.46-1.46A7 7 0 0 0 5 13c0 3.87 3.13 7 7 7v3l4-4-4-4v3c-2.76 0-5-2.24-5-5Z"
                                                    />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <span class="font-medium text-slate-900">{{
                                        p.cost ?? '—'
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <span class="font-medium text-slate-900">{{
                                        costTimes(p, 1.5) ?? '—'
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <div class="flex flex-col items-end">
                                        <input
                                            class="w-24 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm tabular-nums text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                            type="text"
                                            inputmode="decimal"
                                            :value="
                                                sellingPriceDrafts[p.id] ?? p.selling_price ?? ''
                                            "
                                            :disabled="savingSellingPrice === p.id"
                                            placeholder="—"
                                            @focus="startSellingPriceEdit(p.id, p.selling_price)"
                                            @input="
                                                updateSellingPriceDraft(
                                                    p.id,
                                                    ($event.target as HTMLInputElement).value,
                                                )
                                            "
                                            @blur="commitSellingPriceEdit(p.id)"
                                        />
                                        <div class="mt-1 text-xs text-slate-500 tabular-nums">
                                            {{ marginMultiplier(p) ?? '—' }}
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="whitespace-nowrap px-2.5 py-3 text-right tabular-nums text-slate-700"
                                >
                                    <div class="inline-flex items-center justify-end gap-1">
                                        <span
                                            class="font-medium"
                                            :class="
                                                hasWeirdPriceSpread(p)
                                                    ? 'text-rose-700'
                                                    : 'text-slate-900'
                                            "
                                        >
                                            {{ averagePriceOnline(p) ?? '—' }}
                                        </span>
                                        <span
                                            v-if="
                                                averagePriceOnline(p) !== null &&
                                                hasWeirdPriceSpread(p)
                                            "
                                            class="font-bold text-rose-600"
                                            title="Warning: price spread (max-min) exceeds 30%"
                                            aria-label="Warning: price spread (max-min) exceeds 30%"
                                        >
                                            !
                                        </span>
                                    </div>
                                </td>

                                <td
                                    v-for="s in sites"
                                    :key="s.key"
                                    class="px-2.5 py-2.5 text-center tabular-nums text-slate-700"
                                >
                                    <template v-if="quoteFor(p, s.key)">
                                        <div class="group relative inline-block w-full">
                                            <button
                                                class="absolute right-0 top-0 rounded px-1 text-xs font-semibold text-slate-400 opacity-0 transition hover:text-rose-600 group-hover:opacity-100"
                                                type="button"
                                                :aria-label="`Delete quote for ${p.sku} on ${s.name}`"
                                                :disabled="
                                                    deleting?.productId === p.id &&
                                                    deleting?.siteKey === s.key
                                                "
                                                @click.stop="deleteQuote(p.id, s.key)"
                                            >
                                                ×
                                            </button>

                                            <button
                                                class="absolute right-6 top-0 rounded px-1 text-xs font-semibold text-slate-400 opacity-0 transition hover:text-amber-700 group-hover:opacity-100"
                                                type="button"
                                                :aria-label="`Report quote for ${p.sku} on ${s.name}`"
                                                @click.stop="openReportDialog(p, s.key)"
                                            >
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="h-4 w-4"
                                                    aria-hidden="true"
                                                >
                                                    <path
                                                        fill="currentColor"
                                                        d="M6 3h12a1 1 0 0 1 1 1v16l-4-2-4 2-4-2-4 2V4a1 1 0 0 1 1-1Zm1 2v12.382l3-1.5 4 2 4-2 1 .5V5H7Z"
                                                    />
                                                </svg>
                                            </button>

                                            <template v-if="quoteFor(p, s.key)!.status === 'found'">
                                                <div
                                                    class="flex items-baseline justify-center gap-2 px-2.5"
                                                >
                                                    <span
                                                        v-if="
                                                            quoteFor(p, s.key)!.original_price &&
                                                            quoteFor(p, s.key)!.original_price !==
                                                                quoteFor(p, s.key)!.price
                                                        "
                                                        class="text-xs text-slate-500 line-through"
                                                    >
                                                        {{ quoteFor(p, s.key)!.original_price }}
                                                    </span>
                                                    <a
                                                        v-if="quoteFor(p, s.key)!.product_url"
                                                        class="font-medium underline"
                                                        :class="
                                                            quoteFor(p, s.key)!.availability ===
                                                            'sold_out'
                                                                ? 'text-rose-700'
                                                                : 'text-slate-900'
                                                        "
                                                        :href="quoteFor(p, s.key)!.product_url!"
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        {{ quoteFor(p, s.key)!.price ?? '—' }}
                                                    </a>
                                                    <span
                                                        v-else
                                                        class="font-medium"
                                                        :class="
                                                            quoteFor(p, s.key)!.availability ===
                                                            'sold_out'
                                                                ? 'text-rose-700'
                                                                : 'text-slate-900'
                                                        "
                                                    >
                                                        {{ quoteFor(p, s.key)!.price ?? '—' }}
                                                    </span>
                                                </div>
                                                <div
                                                    v-if="quoteFor(p, s.key)!.availability"
                                                    class="mt-0.5 px-2.5 text-[11px]"
                                                    :class="
                                                        quoteFor(p, s.key)!.availability ===
                                                        'sold_out'
                                                            ? 'text-rose-700'
                                                            : 'text-slate-500'
                                                    "
                                                >
                                                    {{
                                                        quoteFor(p, s.key)!.availability ===
                                                        'in_stock'
                                                            ? 'In stock'
                                                            : 'Sold out'
                                                    }}
                                                </div>
                                            </template>
                                            <template
                                                v-else-if="
                                                    quoteFor(p, s.key)!.status === 'not_found'
                                                "
                                            >
                                                <div
                                                    class="flex items-center justify-center px-2.5"
                                                >
                                                    <span
                                                        class="whitespace-nowrap text-xs font-medium leading-none text-slate-400"
                                                        >Not found</span
                                                    >
                                                </div>
                                            </template>
                                            <template v-else
                                                ><span class="px-2.5">Error</span></template
                                            >
                                        </div>
                                    </template>
                                    <template v-else>—</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <PaginationControls
                :current-page="currentPage"
                :last-page="lastPage"
                :total="total"
                :on-change="onPageChange"
            />
        </div>

        <div
            v-if="reporting"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeReportDialog"
        >
            <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Report bad quote</div>
                        <div class="mt-1 text-sm text-slate-600">
                            {{ reporting.sku }} • {{ reporting.siteName }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100"
                        @click="closeReportDialog"
                    >
                        Close
                    </button>
                </div>

                <label
                    class="mt-4 block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >Note (optional)</label
                >
                <textarea
                    v-model="reportNote"
                    rows="4"
                    class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                    placeholder="What looks wrong? (e.g., wrong product, wrong grade, price seems like cart total, etc.)"
                />

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="reportSaving"
                        @click="closeReportDialog"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="reportSaving"
                        @click="submitReport"
                    >
                        {{ reportSaving ? 'Saving…' : 'Save report' }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api } from '../lib/api';
import { formatLocalDate, formatLocalDateTime } from '../lib/datetime';
import {
    formatMoney2,
    formatMoney2OrEmpty,
    formatMoney2OrOriginal,
    parseMoney,
} from '../lib/money';
import { parseNonNegativeIntOrNull } from '../lib/numbers';
import { clearPageState, loadPageState, savePageState } from '../lib/pageState';
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
    handle: string | null;
    price_researched_at: string | null;
    expired: boolean;
    vendor: string | null;
    available: number | null;
    cost: string | null;
    shipping_per_unit: string | null;
    landed_cost: string | null;
    cost_low: string | null;
    cost_high: string | null;
    landed_cost_low: string | null;
    landed_cost_high: string | null;
    po_total_cost: string | null;
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

// Recrawl should still work while the table is loading; only block when a run is actually active.
const isRecrawlBlocked = computed<boolean>(() => running.value || isRunActive.value);

const pageTotals = computed(() => {
    let cost = 0;
    let price = 0;

    for (const p of items.value) {
        const c = parseMoney(p.landed_cost ?? p.cost);
        if (c !== null) cost += c;

        const sp = parseMoney(p.selling_price);
        if (sp !== null) price += sp;
    }

    return {
        cost: cost.toFixed(2),
        price: price.toFixed(2),
    };
});

type ResearchSortKey =
    | 'sku'
    | 'description'
    | 'price_researched_at'
    | 'available'
    | 'cost'
    | 'selling_price'
    | 'multiplier';

const RESEARCH_SORT_KEYS: readonly ResearchSortKey[] = [
    'sku',
    'description',
    'price_researched_at',
    'available',
    'cost',
    'selling_price',
    'multiplier',
] as const;

function isResearchSortKey(value: unknown): value is ResearchSortKey {
    return typeof value === 'string' && (RESEARCH_SORT_KEYS as readonly string[]).includes(value);
}

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
    { key: 'aliexpress', name: 'AliExpress' },
    { key: 'argama_hobby', name: 'Argama Hobby' },
    { key: 'panda_hobby', name: 'Panda Hobby' },
    { key: 'canada_computers', name: 'Canada Computers' },
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

function normalizeRunSites(): void {
    const disabled = new Set(disabledSiteKeys.value);
    const available = allSites
        .filter((s) => s.key !== 'aliexpress' && !disabled.has(s.key))
        .map((s) => s.key);

    // Drop keys that are no longer available (disabled/removed).
    runSites.value = runSites.value.filter((k) => available.includes(k));

    // If user previously had "almost all" selected, auto-include newly added sites.
    // This prevents new crawlers from silently never running due to persisted page state.
    if (runSites.value.length >= Math.max(1, available.length - 1)) {
        runSites.value = [...available];
    }

    // If empty, default to all (except AliExpress).
    if (runSites.value.length === 0) {
        runSites.value = [...available];
    }
}

const quoteSiteOptions = computed<MultiSelectOption[]>(() => {
    return sites.value.map((s) => ({ value: s.key, label: s.name }));
});
const quoteSites = ref<string[]>([]);

const runSiteOptions = computed<MultiSelectOption[]>(() => {
    const disabled = new Set(disabledSiteKeys.value);
    return allSites
        .filter((s) => !disabled.has(s.key))
        .map((s) => ({ value: s.key, label: s.name }));
});
const runSites = ref<string[]>(allSites.filter((s) => s.key !== 'aliexpress').map((s) => s.key));

const sellingPrice = ref<'any' | 'set' | 'missing'>('any');
const shippingPerUnit = ref<'any' | 'set' | 'missing'>('any');
const barcodeFilter = ref<'any' | 'set' | 'missing'>('any');

const productTypes = ref<string[]>([]);
const productTypeOptions = computed<MultiSelectOption[]>(() => {
    return productTypes.value.map((t) => ({ value: t, label: t }));
});
const types = ref<string[]>([]);

const productVendors = ref<string[]>([]);
const vendorOptions = computed<MultiSelectOption[]>(() => {
    return productVendors.value.map((v) => ({ value: v, label: v }));
});
const vendors = ref<string[]>([]);

type PurchaseOrderOption = {
    id: string;
    vendor: string;
    created_at: string | null;
    received_date: string | null;
    counts: { items: number };
};

const purchaseOrderUuids = ref<string[]>([]);
const purchaseOrders = ref<PurchaseOrderOption[]>([]);

const purchaseOrderOptions = computed<MultiSelectOption[]>(() => {
    return purchaseOrders.value.map((po) => ({
        value: po.id,
        label: poLabel(po),
        muted: isPoMuted(po),
    }));
});

const STATE_KEY = 'page_state:price_research';
const hydrating = ref(true);

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

function averagePriceOnline(p: ProductResearch): string | null {
    const disabled = new Set(disabledSiteKeys.value);
    const nums = p.quotes
        .filter((q) => q.status === 'found' && !disabled.has(q.site_key))
        .map((q) => parseMoney(q.price))
        .filter((n): n is number => n !== null);

    if (nums.length === 0) return null;
    const avg = nums.reduce((a, b) => a + b, 0) / nums.length;
    return avg.toFixed(2);
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
    const n = parseMoney(p.landed_cost ?? p.cost);
    if (n === null) return null;
    return (n * factor).toFixed(2);
}

function marginMultiplier(p: ProductResearch): string | null {
    const cost = parseMoney(p.landed_cost ?? p.cost);
    const selling = parseMoney(p.selling_price);
    if (cost === null || selling === null) return null;
    if (cost <= 0) return null;
    return (selling / cost).toFixed(2);
}

function landedSpreadDisplay(p: ProductResearch): { main: string; delta: string | null } {
    if (!p.landed_cost_low || !p.landed_cost_high) {
        return { main: '—', delta: null };
    }

    if (p.landed_cost_low === p.landed_cost_high) {
        return { main: formatMoney2(p.landed_cost_low), delta: null };
    }

    const low = parseMoney(p.landed_cost_low);
    const high = parseMoney(p.landed_cost_high);
    const main = `${formatMoney2(p.landed_cost_low)}–${formatMoney2(p.landed_cost_high)}`;

    if (low === null || high === null || high <= low) {
        return { main, delta: null };
    }

    const half = ((high - low) / 2).toFixed(2);
    return { main, delta: `±${half}` };
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

    const pos = purchaseOrderUuids.value.map((v) => v.trim()).filter(Boolean);
    for (const po of pos) params.append('purchase_order_uuids[]', po);

    if (sellingPrice.value !== 'any') {
        params.set('selling_price', sellingPrice.value);
    }

    if (shippingPerUnit.value !== 'any') {
        params.set('shipping_per_unit', shippingPerUnit.value);
    }

    if (barcodeFilter.value !== 'any') {
        params.set('barcode', barcodeFilter.value);
    }

    for (const v of freshness.value) params.append('freshness[]', v);
    for (const v of types.value) params.append('types[]', v);
    for (const v of vendors.value) params.append('vendors[]', v);
    for (const v of quoteSites.value) params.append('quote_sites[]', v);

    return `/api/v1/price-research/products?${params.toString()}`;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

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
        const json = (await r.json()) as { data?: { types?: string[]; vendors?: string[] } };
        productTypes.value = (json.data?.types ?? []).filter(
            (t) => typeof t === 'string' && t.trim() !== '',
        );
        productVendors.value = (json.data?.vendors ?? []).filter(
            (v) => typeof v === 'string' && v.trim() !== '',
        );
    } catch {
        // ignore
    }
}

function poLabel(po: PurchaseOrderOption): string {
    const short = po.id.slice(0, 8);
    const tail = `${po.vendor} · ${po.counts.items} items · ${short}`;
    const rd = po.received_date?.trim();
    if (rd) {
        return `Received ${formatLocalDate(rd)} · ${tail}`;
    }
    const created = po.created_at ? formatLocalDateTime(po.created_at) : '—';
    return `Not arrived · created ${created} · ${tail}`;
}

function isPoMuted(po: PurchaseOrderOption): boolean {
    // Visually mute POs that are still not arrived.
    return !po.received_date;
}

async function loadPurchaseOrders(): Promise<void> {
    try {
        const r = await fetch('/api/v1/purchase-orders?per_page=200&sort_by=filter');
        if (!r.ok) {
            purchaseOrders.value = [];
            return;
        }
        const json = (await r.json()) as { data?: PurchaseOrderOption[] };
        purchaseOrders.value = Array.isArray(json.data) ? json.data : [];
    } catch {
        purchaseOrders.value = [];
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
            { force, site_keys: runSites.value.length > 0 ? runSites.value : undefined },
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

const savingBarcode = ref<string | null>(null);
const editingBarcodeId = ref<string | null>(null);
const barcodeDrafts = reactive<Record<string, string>>({});

const savingAvailable = ref<string | null>(null);
const editingAvailableId = ref<string | null>(null);
const availableDrafts = reactive<Record<string, string>>({});

function startAvailableEdit(productId: string, current: number | null): void {
    editingAvailableId.value = productId;
    if (availableDrafts[productId] === undefined) {
        availableDrafts[productId] = current === null ? '' : String(current);
    }
}

function updateAvailableDraft(productId: string, value: string): void {
    availableDrafts[productId] = value;
}

function commitAvailableEdit(productId: string): void {
    // Prevent Enter + blur from committing twice (second commit would send empty after draft deletion)
    if (editingAvailableId.value !== productId) {
        return;
    }

    editingAvailableId.value = null;
    const value = availableDrafts[productId] ?? '';
    delete availableDrafts[productId];
    void saveAvailable(productId, value);
}

async function saveAvailable(productId: string, value: string | null): Promise<void> {
    savingAvailable.value = productId;
    error.value = null;
    const row = items.value.find((p) => p.id === productId);
    const previous = row?.available ?? null;

    try {
        const available = parseNonNegativeIntOrNull(value ?? '');

        if (row) {
            row.available = available;
        }

        const res = await api.patch(
            `/api/v1/products/${productId}/available`,
            { available },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            if (row) row.available = previous;
            error.value = 'Failed to save available quantity.';
            return;
        }

        const saved = (res.data?.data?.available as number | null | undefined) ?? null;
        if (row) row.available = saved;
    } catch {
        if (row) row.available = previous;
        error.value = 'Failed to save available quantity.';
    } finally {
        savingAvailable.value = null;
    }
}

function startBarcodeEdit(productId: string, current: string | null): void {
    editingBarcodeId.value = productId;
    if (barcodeDrafts[productId] === undefined) {
        barcodeDrafts[productId] = current ?? '';
    }
}

function updateBarcodeDraft(productId: string, value: string): void {
    barcodeDrafts[productId] = value;
}

function commitBarcodeEdit(productId: string): void {
    // Prevent Enter + blur from committing twice (second commit would send empty after draft deletion)
    if (editingBarcodeId.value !== productId) {
        return;
    }

    editingBarcodeId.value = null;
    const value = barcodeDrafts[productId] ?? '';
    delete barcodeDrafts[productId];
    void saveBarcode(productId, value);
}

async function saveBarcode(productId: string, value: string | null): Promise<void> {
    savingBarcode.value = productId;
    error.value = null;
    const row = items.value.find((p) => p.id === productId);
    const previous = row?.barcode ?? null;

    try {
        const barcode = value !== null && value.trim() !== '' ? value.trim() : null;
        if (row) {
            row.barcode = barcode;
        }

        if (!row) {
            error.value = 'Failed to save barcode.';
            return;
        }

        const res = await api.patch(
            `/api/v1/products/${productId}/barcode`,
            { barcode },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            row.barcode = previous;
            error.value = res.data?.message ?? 'Failed to save barcode.';
            return;
        }

        const saved = (res.data?.data?.barcode as string | null | undefined) ?? null;
        row.barcode = saved;
    } catch {
        if (row) {
            row.barcode = previous;
        }
        error.value = 'Failed to save barcode.';
    } finally {
        savingBarcode.value = null;
    }
}

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
    // Prevent Enter + blur from committing twice (second commit would send empty after draft deletion)
    if (editingSellingPriceId.value !== productId) {
        return;
    }

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
    if (isRecrawlBlocked.value) {
        message.value = isRunActive.value
            ? 'A price research run is already in progress. Please wait for it to finish.'
            : 'Already running…';
        return;
    }
    running.value = true;
    recrawlingProductId.value = productId;
    error.value = null;
    message.value = null;
    message.value = 'Starting recrawl…';

    try {
        const res = await api.post(
            '/api/v1/price-research/run',
            {
                force: true,
                ids: [productId],
                site_keys: runSites.value.length > 0 ? runSites.value : undefined,
            },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            error.value =
                (res.data?.message as string | undefined) ??
                `Failed to recrawl product (HTTP ${res.status}).`;
            return;
        }

        const runId = res.data?.run_id as string | undefined;
        if (runId) {
            activeRunId.value = runId;
            message.value = 'Queued recrawl for this product. Showing live status below…';
            await pollRun(runId);
            message.value = 'Recrawl completed.';
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
        normalizeRunSites();
    } catch {
        // ignore
    }
}

onMounted(() => {
    const saved = loadPageState<{
        search?: string;
        perPage?: number;
        page?: number;
        sortBy?: ResearchSortKey;
        sortDir?: 'asc' | 'desc';
        purchaseOrderUuid?: string; // legacy
        purchaseOrderUuids?: string[];
        sellingPrice?: 'any' | 'set' | 'missing';
        shippingPerUnit?: 'any' | 'set' | 'missing';
        barcodeFilter?: 'any' | 'set' | 'missing';
        freshness?: string[];
        types?: string[];
        vendors?: string[];
        quoteSites?: string[];
        runSites?: string[];
        disabledSiteKeys?: string[];
    }>(STATE_KEY);

    if (saved) {
        if (typeof saved.search === 'string') search.value = saved.search;
        if (typeof saved.perPage === 'number') perPage.value = saved.perPage;
        if (typeof saved.page === 'number') page.value = saved.page;
        if (isResearchSortKey(saved.sortBy)) sortBy.value = saved.sortBy;
        if (saved.sortDir) sortDir.value = saved.sortDir;
        if (Array.isArray(saved.purchaseOrderUuids))
            purchaseOrderUuids.value = saved.purchaseOrderUuids;
        else if (
            typeof saved.purchaseOrderUuid === 'string' &&
            saved.purchaseOrderUuid.trim() !== ''
        )
            purchaseOrderUuids.value = [saved.purchaseOrderUuid.trim()];
        if (saved.sellingPrice) sellingPrice.value = saved.sellingPrice;
        if (saved.shippingPerUnit) shippingPerUnit.value = saved.shippingPerUnit;
        if (saved.barcodeFilter) barcodeFilter.value = saved.barcodeFilter;
        if (Array.isArray(saved.freshness)) freshness.value = saved.freshness;
        if (Array.isArray(saved.types)) types.value = saved.types;
        if (Array.isArray(saved.vendors)) vendors.value = saved.vendors;
        if (Array.isArray(saved.quoteSites)) quoteSites.value = saved.quoteSites;
        if (Array.isArray(saved.runSites)) runSites.value = saved.runSites;
        if (Array.isArray(saved.disabledSiteKeys)) disabledSiteKeys.value = saved.disabledSiteKeys;
    }

    hydrating.value = false;

    void load();
    void loadLatestRun();
    void loadProductFilterOptions();
    void loadPriceResearchFilterOptions();
    void loadPurchaseOrders();
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
watch(
    [
        search,
        perPage,
        sellingPrice,
        shippingPerUnit,
        barcodeFilter,
        purchaseOrderUuids,
        vendors,
        types,
        freshness,
        quoteSites,
        sortBy,
        sortDir,
    ],
    () => {
        if (hydrating.value) return;
        page.value = 1;
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => void load(), 250);
    },
    { deep: true },
);
watch(page, () => {
    if (hydrating.value) return;
    void load();
});
watch(
    disabledSiteKeys,
    () => {
        if (hydrating.value) return;
        const disabled = new Set(disabledSiteKeys.value);
        quoteSites.value = quoteSites.value.filter((k) => !disabled.has(k));
        normalizeRunSites();
    },
    { deep: true },
);

watch(
    [
        search,
        perPage,
        page,
        sortBy,
        sortDir,
        purchaseOrderUuids,
        sellingPrice,
        shippingPerUnit,
        barcodeFilter,
        vendors,
        types,
        freshness,
        quoteSites,
        runSites,
        disabledSiteKeys,
    ],
    () => {
        if (hydrating.value) return;
        savePageState(STATE_KEY, {
            search: search.value,
            perPage: perPage.value,
            page: page.value,
            sortBy: sortBy.value,
            sortDir: sortDir.value,
            purchaseOrderUuids: purchaseOrderUuids.value,
            sellingPrice: sellingPrice.value,
            shippingPerUnit: shippingPerUnit.value,
            barcodeFilter: barcodeFilter.value,
            freshness: freshness.value,
            types: types.value,
            vendors: vendors.value,
            quoteSites: quoteSites.value,
            runSites: runSites.value,
            disabledSiteKeys: disabledSiteKeys.value,
        });
    },
    { deep: true },
);

function resetPageState(): void {
    clearPageState(STATE_KEY);
    search.value = '';
    perPage.value = 50;
    page.value = 1;
    sortBy.value = 'price_researched_at';
    sortDir.value = 'desc';
    purchaseOrderUuids.value = [];
    sellingPrice.value = 'any';
    shippingPerUnit.value = 'any';
    barcodeFilter.value = 'any';
    freshness.value = [];
    types.value = [];
    vendors.value = [];
    quoteSites.value = [];
    disabledSiteKeys.value = [];
    runSites.value = allSites.filter((s) => s.key !== 'aliexpress').map((s) => s.key);
    void load();
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Price research</h1>
            </div>

            <div class="flex items-center gap-2">
                <button
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                    type="button"
                    :disabled="isBusy"
                    @click="resetPageState"
                >
                    Reset
                </button>
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
                        v-model="runSites"
                        label="Run sites"
                        :options="runSiteOptions"
                        placeholder="All (excluding AliExpress)"
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
                    <MultiSelectFilter
                        v-model="vendors"
                        label="Vendor"
                        :options="vendorOptions"
                        placeholder="All vendors"
                    />
                </div>

                <div class="min-w-[260px] flex-[2_1_360px]">
                    <MultiSelectFilter
                        v-model="purchaseOrderUuids"
                        label="PO"
                        :options="purchaseOrderOptions"
                        placeholder="All POs"
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

                <div class="min-w-[180px] flex-[1_1_220px]">
                    <label
                        class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >Shipping cost</label
                    >
                    <select
                        v-model="shippingPerUnit"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                    >
                        <option value="any">All</option>
                        <option value="set">Has shipping cost</option>
                        <option value="missing">Missing shipping cost</option>
                    </select>
                </div>

                <div class="min-w-[180px] flex-[1_1_220px]">
                    <label
                        class="block text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >Barcode</label
                    >
                    <select
                        v-model="barcodeFilter"
                        class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                    >
                        <option value="any">All</option>
                        <option value="set">Has barcode</option>
                        <option value="missing">Missing barcode</option>
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
                                <th class="px-4 py-3">BARCODE</th>
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
                                <th class="px-4 py-3">VENDOR</th>
                                <th class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="hover:underline"
                                        @click="onSortChange('available')"
                                    >
                                        AVAILABLE{{ sortIndicator('available') }}
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="hover:underline"
                                        @click="onSortChange('cost')"
                                    >
                                        UNIT COST{{ sortIndicator('cost') }}
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right">SHIP/UNIT</th>
                                <th class="px-4 py-3 text-right">LANDED</th>
                                <th class="px-4 py-3 text-right">LANDED (LOW-HIGH)</th>
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
                                <td class="px-4 py-4 text-slate-600" :colspan="12 + sites.length">
                                    No products found.
                                </td>
                            </tr>

                            <tr v-for="p in items" :key="p.id" class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-900">{{ p.sku }}</td>
                                <td class="px-4 py-3 text-slate-700">
                                    <input
                                        class="w-40 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                        type="text"
                                        inputmode="numeric"
                                        :value="barcodeDrafts[p.id] ?? p.barcode ?? ''"
                                        :disabled="savingBarcode === p.id"
                                        placeholder="—"
                                        @focus="startBarcodeEdit(p.id, p.barcode)"
                                        @input="
                                            updateBarcodeDraft(
                                                p.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                        @keydown.enter.prevent="commitBarcodeEdit(p.id)"
                                        @blur="commitBarcodeEdit(p.id)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    <div class="font-medium text-slate-900">
                                        {{ p.description }}
                                    </div>
                                    <div
                                        v-if="p.handle && p.handle.trim() !== ''"
                                        class="mt-0.5 font-mono text-xs text-slate-500"
                                    >
                                        {{ p.handle }}
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
                                                :disabled="
                                                    isRecrawlBlocked || recrawlingProductId === p.id
                                                "
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
                                <td class="px-4 py-3 text-slate-700">
                                    {{ p.vendor ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <input
                                        class="w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm tabular-nums text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                        type="text"
                                        inputmode="numeric"
                                        :value="
                                            availableDrafts[p.id] ??
                                            (p.available === null ? '' : String(p.available))
                                        "
                                        :disabled="savingAvailable === p.id"
                                        placeholder="—"
                                        @focus="startAvailableEdit(p.id, p.available)"
                                        @input="
                                            updateAvailableDraft(
                                                p.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                        @keydown.enter.prevent="commitAvailableEdit(p.id)"
                                        @blur="commitAvailableEdit(p.id)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <span class="font-medium text-slate-900">{{
                                        formatMoney2OrEmpty(p.cost) || '—'
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <span class="font-medium text-slate-900">{{
                                        formatMoney2OrEmpty(p.shipping_per_unit) || '—'
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <span class="font-medium text-slate-900">{{
                                        formatMoney2OrEmpty(p.landed_cost) || '—'
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                    <span class="text-slate-600">{{
                                        landedSpreadDisplay(p).main
                                    }}</span>
                                    <div
                                        v-if="landedSpreadDisplay(p).delta"
                                        class="mt-0.5 text-xs font-medium text-slate-500 tabular-nums"
                                    >
                                        {{ landedSpreadDisplay(p).delta }}
                                    </div>
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
                                                        {{
                                                            formatMoney2OrOriginal(
                                                                quoteFor(p, s.key)!.original_price,
                                                            )
                                                        }}
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
                                                        {{
                                                            formatMoney2OrOriginal(
                                                                quoteFor(p, s.key)!.price,
                                                            )
                                                        }}
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
                                                        {{
                                                            formatMoney2OrOriginal(
                                                                quoteFor(p, s.key)!.price,
                                                            )
                                                        }}
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
                        <tfoot class="bg-slate-50">
                            <tr class="text-sm font-semibold text-slate-900">
                                <td class="px-4 py-3 text-slate-600">Page total</td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ pageTotals.cost }}
                                </td>
                                <td class="px-4 py-3"></td>
                                <td class="px-2.5 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ pageTotals.price }}
                                </td>
                                <td class="px-2.5 py-3"></td>
                                <td v-for="s in sites" :key="s.key" class="px-2.5 py-3"></td>
                            </tr>
                        </tfoot>
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

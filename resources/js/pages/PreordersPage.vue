<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import OpenStorePreorderDialog from '../components/storePreorders/OpenStorePreorderDialog.vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import SortableTh from '../components/ui/SortableTh.vue';
import axios from 'axios';
import { api, extractApiError } from '../lib/api';
import {
    calendarMonthsBetween,
    dayBeforeYmd,
    formatTorontoDate,
    formatTorontoDateTime,
    torontoTodayYmd,
} from '../lib/datetime';
import { formatMoney2OrEmpty } from '../lib/money';

type PreorderRow = {
    sku: string;
    barcode: string | null;
    product_name: string;
    series: string | null;
    release_date: string | null;
    manufacturer: string | null;
    category: string | null;
    price_stock: string | null;
    price_preorder: string | null;
    price_backorder: string | null;
    unit_selling_price: string | null;
    quantity_preorder: number | null;
    po_due_date: string | null;
    eta_date: string | null;
    eta_lead_months: number | null;
    is_new: boolean;
    not_in_import: boolean;
    image_url: string | null;
    image_download_status: string;
    plamod_pdp_url: string;
    not_interested: boolean;
    store_preorder_status: 'open' | 'closed' | null;
    store_preorder_id: string | null;
    plamod_in_stock: boolean;
    plamod_preorder_closed: boolean;
    plamod_stock_listing: boolean;
};

type PreorderSort =
    'name' | 'release' | 'category' | 'stock' | 'sell' | 'qty' | 'closing' | 'eta' | 'eta_months';

type CategoryFacet = {
    category: string;
    count: number;
};

type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        categories?: string[];
        category_facets?: CategoryFacet[];
    };
};

type FailureSummaryRow = {
    error_kind: string;
    error_message: string;
    count: number;
};

type SyncStatus = {
    status: string;
    sync_log_id: number | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    counts: Record<string, number | string | FailureSummaryRow[]>;
    error_summary: string | null;
};

type SearchLinesResult = {
    matched: Array<{ line: string; sku: string; product_name: string; in_snapshot?: boolean }>;
    plamod_only: Array<{ line: string; sku: string; product_name: string; plamod_pdp_url: string }>;
    not_found: string[];
};

type ManufacturerFilterRow = {
    id: number;
    filter_type: 'series' | 'category_line';
    name: string;
    plamod_preorder_count: number | null;
    plamod_other_count: number | null;
    decision: 'undecided' | 'include' | 'exclude';
    last_seen_at: string | null;
};

type ManufacturerFiltersGrouped = {
    undecided: ManufacturerFilterRow[];
    include: ManufacturerFilterRow[];
    exclude: ManufacturerFilterRow[];
    counts: { undecided: number; include: number; exclude: number };
};

const rows = ref<PreorderRow[]>([]);
const meta = ref<Paginated<PreorderRow>['meta'] | null>(null);
const categoryFacets = ref<CategoryFacet[]>([]);
const categoryFilter = ref<string[]>([]);
const loading = ref(false);
const errorMessage = ref<string | null>(null);

const search = ref('');
const newOnly = ref(false);
const futureReleasesOnly = ref(false);
const storeOffer = ref<'all' | 'not_opened' | 'opened'>('not_opened');
const interest = ref<'interested' | 'not_interested' | 'all'>('interested');
const interestBusy = ref(false);
const interestMessage = ref<string | null>(null);
const includeClosed = ref(false);
const sort = ref<PreorderSort>('closing');
const sortDir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const perPage = ref(50);
const selectedSkus = ref<string[]>([]);
const openDialogOpen = ref(false);
const openBusy = ref(false);
const openError = ref<string | null>(null);
const openDefaultDepositPercent = ref('20');
const openShippingPercent = ref(5);
const openPriceBySku = ref<Record<string, string>>({});
const openDepositBySku = ref<Record<string, string>>({});
const openCapBySku = ref<Record<string, string>>({});
const openClosingBySku = ref<Record<string, string>>({});
const openResultMessage = ref<string | null>(null);

const syncStatus = ref<SyncStatus | null>(null);
const syncing = ref(false);
const syncError = ref<string | null>(null);

const excludedCategories = ref<string[]>([]);
const settingsSaving = ref(false);
const settingsError = ref<string | null>(null);

const manufacturerFilters = ref<ManufacturerFiltersGrouped | null>(null);
const manufacturerFiltersLoading = ref(false);
const manufacturerFiltersDiscovering = ref(false);
const manufacturerFiltersSaving = ref(false);
const manufacturerFiltersError = ref<string | null>(null);

const pasteLines = ref('');
const pasteSearching = ref(false);
const pasteSearchStatus = ref<string | null>(null);
const pasteResult = ref<SearchLinesResult | null>(null);
const pasteGridActive = ref(false);
const pasteGridRows = ref<PreorderRow[]>([]);
const pasteError = ref<string | null>(null);

function togglePreorderSort(key: PreorderSort): void {
    if (sort.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    sort.value = key;
    sortDir.value = 'asc';
}

function preorderSortValue(row: PreorderRow, key: PreorderSort): string | number | null {
    if (key === 'name') {
        return row.product_name;
    }
    if (key === 'release') {
        return row.release_date;
    }
    if (key === 'category') {
        return row.category;
    }
    if (key === 'stock') {
        const n = Number(row.price_preorder ?? row.price_stock ?? '');
        return Number.isFinite(n) ? n : null;
    }
    if (key === 'sell') {
        const n = Number(row.unit_selling_price ?? '');
        return Number.isFinite(n) ? n : null;
    }
    if (key === 'qty') {
        return row.quantity_preorder;
    }
    if (key === 'eta') {
        return row.eta_date;
    }
    if (key === 'eta_months') {
        return etaLeadMonths(row);
    }

    return row.po_due_date;
}

function etaLeadMonths(row: PreorderRow): number | null {
    if (row.eta_lead_months !== null && row.eta_lead_months !== undefined) {
        return row.eta_lead_months;
    }

    return calendarMonthsBetween(row.release_date, row.eta_date);
}

function etaLeadMonthsLabel(row: PreorderRow): string {
    const months = etaLeadMonths(row);
    if (months === null) {
        return '';
    }

    return `${months} mo`;
}

function sortPreorderRows(
    list: PreorderRow[],
    key: PreorderSort,
    dir: 'asc' | 'desc',
): PreorderRow[] {
    const sign = dir === 'desc' ? -1 : 1;
    return [...list].sort((a, b) => {
        const av = preorderSortValue(a, key);
        const bv = preorderSortValue(b, key);
        if (av === null || av === '') {
            return 1;
        }
        if (bv === null || bv === '') {
            return -1;
        }
        if (typeof av === 'number' && typeof bv === 'number') {
            return (av - bv) * sign;
        }

        return String(av).localeCompare(String(bv)) * sign;
    });
}

function isFutureRelease(row: PreorderRow): boolean {
    const release = (row.release_date ?? '').trim();
    if (release === '') {
        return false;
    }

    return release >= torontoTodayYmd();
}

const displayRows = computed(() => {
    let source = pasteGridActive.value ? pasteGridRows.value : rows.value;
    if (pasteGridActive.value) {
        source = sortPreorderRows(source, sort.value, sortDir.value);
        if (futureReleasesOnly.value) {
            source = source.filter(isFutureRelease);
        }
    }

    return source;
});

const LIVE_SEARCH_POLL_MS = 3000;
const MANUFACTURER_DISCOVER_POLL_MS = 3000;

let pollTimer: number | null = null;

function kitCountLabel(count: number): string {
    return `${count} kit${count === 1 ? '' : 's'}`;
}

function facetOption(facet: CategoryFacet, hidden: boolean): MultiSelectOption {
    return {
        value: facet.category,
        label: facet.category,
        subLabel: hidden ? `${kitCountLabel(facet.count)} · hidden` : kitCountLabel(facet.count),
        muted: hidden,
    };
}

const categoryFilterOptions = computed<MultiSelectOption[]>(() =>
    categoryFacets.value.map((facet) =>
        facetOption(facet, excludedCategories.value.includes(facet.category)),
    ),
);

const excludedCategoryOptions = computed<MultiSelectOption[]>(() =>
    categoryFacets.value.map((facet) => facetOption(facet, false)),
);

const browseableCategories = computed<CategoryFacet[]>(() =>
    categoryFacets.value.filter(
        (facet) => facet.count > 0 && !excludedCategories.value.includes(facet.category),
    ),
);

function browseCursor(): string | null {
    if (categoryFilter.value.length === 0) {
        return null;
    }
    const selected = [...categoryFilter.value].sort((a, b) => a.localeCompare(b));
    return selected[selected.length - 1] ?? null;
}

const viewingCategory = computed<CategoryFacet | null>(() => {
    if (categoryFilter.value.length !== 1) {
        return null;
    }
    const name = categoryFilter.value[0] ?? '';
    return categoryFacets.value.find((facet) => facet.category === name) ?? null;
});

const nextBrowseCategory = computed<CategoryFacet | null>(() => {
    const list = browseableCategories.value;
    if (list.length === 0) {
        return null;
    }
    const cursor = browseCursor();
    if (cursor === null) {
        return list[0] ?? null;
    }
    const idx = list.findIndex((facet) => facet.category === cursor);
    if (idx >= 0) {
        return list[idx + 1] ?? null;
    }
    return list.find((facet) => facet.category.localeCompare(cursor) > 0) ?? null;
});

const prevBrowseCategory = computed<CategoryFacet | null>(() => {
    const list = browseableCategories.value;
    const cursor = browseCursor();
    if (cursor === null || list.length === 0) {
        return null;
    }
    const idx = list.findIndex((facet) => facet.category === cursor);
    if (idx > 0) {
        return list[idx - 1] ?? null;
    }
    if (idx === 0) {
        return null;
    }
    const before = list.filter((facet) => facet.category.localeCompare(cursor) < 0);
    return before[before.length - 1] ?? null;
});

const browsePositionLabel = computed(() => {
    const list = browseableCategories.value;
    const current = viewingCategory.value;
    if (current === null || list.length === 0) {
        return `${list.length} ${list.length === 1 ? 'category' : 'categories'} with kits`;
    }
    const idx = list.findIndex((facet) => facet.category === current.category);
    if (idx < 0) {
        return `${current.category} · ${kitCountLabel(current.count)}`;
    }
    return `${current.category} · ${kitCountLabel(current.count)} · ${idx + 1} of ${list.length}`;
});

const syncBusy = computed(
    () => syncing.value || ['queued', 'running'].includes(syncStatus.value?.status ?? ''),
);

const syncAutoResumeAttempt = computed(() =>
    Number(syncStatus.value?.counts?.auto_resume_attempt ?? 0),
);

const syncFailureSummary = computed((): FailureSummaryRow[] => {
    const raw = syncStatus.value?.counts?.failure_summary;
    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter(
        (row): row is FailureSummaryRow =>
            typeof row === 'object' &&
            row !== null &&
            typeof (row as FailureSummaryRow).error_kind === 'string' &&
            typeof (row as FailureSummaryRow).error_message === 'string' &&
            typeof (row as FailureSummaryRow).count === 'number',
    );
});

const syncManufacturerFailureLabel = computed((): string | null => {
    const failed = Number(syncStatus.value?.counts?.manufacturer_export_failed ?? 0);
    const succeeded = Number(syncStatus.value?.counts?.manufacturer_export_succeeded ?? 0);
    const retried = Number(syncStatus.value?.counts?.manufacturer_export_retried ?? 0);
    if (failed <= 0) {
        return null;
    }

    return `Manufacturer export: ${succeeded} succeeded, ${failed} failed (${retried} retries).`;
});

const syncPhaseLabel = computed(() => {
    const counts = syncStatus.value?.counts ?? {};
    const phase = String(counts.phase ?? '');
    if (phase === 'queued' && syncAutoResumeAttempt.value > 0) {
        return `Auto-resuming sync (attempt ${syncAutoResumeAttempt.value}/5)…`;
    }
    if (phase === 'discover') {
        return 'Discovering manufacturer filters…';
    }
    if (phase === 'hub_export') {
        return 'Exporting kits + figures hub (New Preorders + Offer Sheets)…';
    }
    if (phase === 'manufacturer_merged' || phase === 'export') {
        const processed = Number(
            counts.filters_processed ?? counts.manufacturer_filters_processed ?? 0,
        );
        const total = Number(counts.filters_total ?? counts.manufacturer_filters_total ?? 0);
        const current = String(
            counts.current_filter ?? counts.manufacturer_current_filter ?? '',
        ).trim();
        const rows = Number(counts.rows_merged ?? counts.manufacturer_row_count ?? 0);
        const expected = Number(counts.expected_row_count ?? 0);
        const base =
            total > 0
                ? `Exporting Bandai preorders (${processed}/${total})`
                : 'Exporting Bandai preorders…';
        const suffix = current !== '' ? ` — ${current}` : '';
        const coverage =
            expected > 0 ? ` · ${rows} of ~${expected}` : rows > 0 ? ` · ${rows} SKUs` : '';

        return `${base}${suffix}${coverage}`;
    }
    if (phase === 'pdp_enrich') {
        const done = Number(counts.pdp_enrich_done ?? 0);
        const total = Number(counts.pdp_enrich_total ?? 0);
        if (total > 0) {
            return `Enriching preorder details from PDPs (${done}/${total})…`;
        }

        return 'Enriching preorder details from PDPs…';
    }
    if (phase === 'manufacturer_export' || phase === 'manufacturer_recovery') {
        const processed = Number(counts.manufacturer_filters_processed ?? 0);
        const total = Number(counts.manufacturer_filters_total ?? 0);
        const current = String(counts.manufacturer_current_filter ?? '').trim();
        const succeeded = Number(counts.manufacturer_export_succeeded ?? 0);
        const failed = Number(counts.manufacturer_export_failed ?? 0);
        const prefix =
            phase === 'manufacturer_recovery'
                ? 'Retrying failed filters'
                : 'Exporting manufacturer filters';
        if (total > 0) {
            const base = `${prefix} (${processed}/${total})`;
            if (current !== '') {
                return `${base} — ${current}`;
            }
            if (succeeded > 0 || failed > 0) {
                return `${base} · ${succeeded} ok, ${failed} failed`;
            }

            return base;
        }

        return `${prefix}…`;
    }
    if (phase === 'import') {
        return 'Merging and importing rows…';
    }
    if (phase === 'images') {
        const total = Number(counts.images_total ?? 0);
        const done = Number(counts.images_completed ?? 0) + Number(counts.images_failed ?? 0);
        return `Downloading images (${done}/${total})`;
    }
    if (syncBusy.value) {
        return 'Sync in progress…';
    }

    return '';
});

const syncProgressPercent = computed((): number | null => {
    const counts = syncStatus.value?.counts ?? {};
    const phase = String(counts.phase ?? '');
    if (phase === 'manufacturer_export' || phase === 'manufacturer_recovery') {
        const processed = Number(counts.manufacturer_filters_processed ?? 0);
        const total = Number(counts.manufacturer_filters_total ?? 0);
        if (total <= 0) {
            return null;
        }

        return Math.min(100, Math.round((processed / total) * 100));
    }
    if (phase === 'images') {
        const total = Number(counts.images_total ?? 0);
        const done = Number(counts.images_completed ?? 0) + Number(counts.images_failed ?? 0);
        if (total <= 0) {
            return null;
        }

        return Math.min(100, Math.round((done / total) * 100));
    }

    return null;
});

const syncProgressDetail = computed((): string | null => {
    if (!syncBusy.value) {
        return null;
    }

    const counts = syncStatus.value?.counts ?? {};
    const phase = String(counts.phase ?? '');
    if (phase === 'manufacturer_export' || phase === 'manufacturer_recovery') {
        const succeeded = Number(counts.manufacturer_export_succeeded ?? 0);
        const failed = Number(counts.manufacturer_export_failed ?? 0);
        if (succeeded > 0 || failed > 0) {
            return `${succeeded} succeeded · ${failed} failed so far`;
        }
    }

    return null;
});

function stopPolling(): void {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function startPolling(): void {
    stopPolling();
    pollTimer = window.setInterval(async () => {
        await loadSyncStatus();
        if (!syncBusy.value) {
            stopPolling();
            await fetchRows();
        } else {
            await fetchRows({ silent: true });
        }
    }, 3000);
}

function clearPasteGrid(): void {
    pasteGridActive.value = false;
    pasteGridRows.value = [];
}

function mergePasteRowsInLineOrder(
    lines: string[],
    matched: SearchLinesResult['matched'],
    importedRows: PreorderRow[],
    plamodOnly: SearchLinesResult['plamod_only'],
    liveRows: PreorderRow[],
): PreorderRow[] {
    const importedBySku = new Map(importedRows.map((row) => [row.sku, row]));
    const matchedByLine = new Map(
        matched.map((hit) => [hit.line, importedBySku.get(hit.sku) ?? null]),
    );
    const liveByLine = new Map(plamodOnly.map((hit, index) => [hit.line, liveRows[index] ?? null]));

    const ordered: PreorderRow[] = [];
    for (const line of lines) {
        const row = matchedByLine.get(line) ?? liveByLine.get(line) ?? null;
        if (row) {
            ordered.push(row);
        }
    }

    return ordered;
}

async function fetchRows(opts?: { silent?: boolean }): Promise<void> {
    if (!opts?.silent) {
        clearPasteGrid();
        loading.value = true;
        errorMessage.value = null;
    }
    try {
        const res = await api.get<Paginated<PreorderRow>>('/api/v1/preorders', {
            params: {
                page: page.value,
                per_page: perPage.value,
                search: search.value.trim() || undefined,
                new_only: newOnly.value ? 1 : undefined,
                future_releases_only: futureReleasesOnly.value ? 1 : undefined,
                store_offer: storeOffer.value,
                interest: interest.value,
                sort: sort.value,
                sort_dir: sortDir.value,
                include_closed: includeClosed.value ? 1 : undefined,
                categories: categoryFilter.value.length > 0 ? categoryFilter.value : undefined,
            },
        });
        rows.value = res.data.data ?? [];
        meta.value = res.data.meta ?? null;
        if (res.data.meta?.category_facets) {
            categoryFacets.value = res.data.meta.category_facets;
        }
        pruneSelectedSkus();
    } catch (err) {
        if (!opts?.silent) {
            errorMessage.value = extractApiError(err);
        }
    } finally {
        if (!opts?.silent) {
            loading.value = false;
        }
    }
}

async function loadSettings(): Promise<void> {
    try {
        const res = await api.get<{ data: { excluded_categories: string[] } }>(
            '/api/v1/preorders/settings',
        );
        excludedCategories.value = res.data.data?.excluded_categories ?? [];
    } catch (err) {
        settingsError.value = err instanceof Error ? err.message : String(err);
    }
}

async function loadCatalogPricing(): Promise<void> {
    try {
        const res = await api.get<{
            data: { default_deposit_percent: string };
        }>('/api/v1/maintenance/opv-catalog-pricing');
        const deposit = Number(res.data.data?.default_deposit_percent ?? 20);
        if (Number.isFinite(deposit) && deposit >= 1 && deposit <= 100) {
            openDefaultDepositPercent.value = String(deposit);
        }
    } catch {
        openDefaultDepositPercent.value = '20';
    }
}

async function loadShippingEstimate(): Promise<void> {
    try {
        const res = await api.get<{ data: { shipping_percent: number } }>(
            '/api/v1/plamod/restock/settings',
        );
        const percent = Number(res.data.data?.shipping_percent ?? 5);
        if (Number.isFinite(percent) && percent >= 0 && percent <= 100) {
            openShippingPercent.value = percent;
        }
    } catch {
        openShippingPercent.value = 5;
    }
}

async function loadManufacturerFilters(): Promise<void> {
    manufacturerFiltersLoading.value = true;
    manufacturerFiltersError.value = null;
    try {
        const res = await api.get<{ data: ManufacturerFiltersGrouped }>(
            '/api/v1/preorders/manufacturer-filters',
        );
        manufacturerFilters.value = res.data.data ?? null;
    } catch (err) {
        manufacturerFiltersError.value = err instanceof Error ? err.message : String(err);
    } finally {
        manufacturerFiltersLoading.value = false;
    }
}

async function pollManufacturerFilterDiscoverJob(
    jobId: string,
): Promise<ManufacturerFiltersGrouped> {
    for (let attempt = 0; attempt < 400; attempt += 1) {
        const res = await api.post<{
            data: {
                status: string;
                ok?: boolean;
                filters?: ManufacturerFiltersGrouped;
                error_message?: string | null;
            };
        }>(
            '/api/v1/preorders/manufacturer-filters/discover',
            { job_id: jobId },
            { timeout: 30_000 },
        );

        const status = res.data.data?.status ?? '';
        if (status === 'completed') {
            if (res.data.data?.filters) {
                return res.data.data.filters;
            }
            throw new Error('Discover completed without filter data.');
        }
        if (status === 'failed') {
            throw new Error(res.data.data?.error_message ?? 'Discover failed');
        }
        if (status === 'missing') {
            throw new Error(res.data.data?.error_message ?? 'Discover job not found.');
        }

        await sleep(MANUFACTURER_DISCOVER_POLL_MS);
    }

    throw new Error('Discover timed out while waiting for background job.');
}

async function discoverManufacturerFilters(): Promise<void> {
    manufacturerFiltersDiscovering.value = true;
    manufacturerFiltersError.value = null;
    try {
        const startRes = await api.post<{ data: { job_id: string; status: string } }>(
            '/api/v1/preorders/manufacturer-filters/discover',
            {},
            { timeout: 30_000 },
        );
        const jobId = startRes.data.data?.job_id ?? '';
        if (jobId === '') {
            manufacturerFiltersError.value = 'Discover did not return a job id.';
            return;
        }

        manufacturerFilters.value = await pollManufacturerFilterDiscoverJob(jobId);
    } catch (err) {
        manufacturerFiltersError.value = extractApiError(err);
    } finally {
        manufacturerFiltersDiscovering.value = false;
    }
}

async function setManufacturerFilterDecision(
    row: ManufacturerFilterRow,
    decision: ManufacturerFilterRow['decision'],
): Promise<void> {
    manufacturerFiltersSaving.value = true;
    manufacturerFiltersError.value = null;
    try {
        const res = await api.put<{ data: ManufacturerFiltersGrouped }>(
            '/api/v1/preorders/manufacturer-filters',
            {
                updates: [{ id: row.id, decision }],
            },
        );
        manufacturerFilters.value = res.data.data ?? null;
    } catch (err) {
        manufacturerFiltersError.value = err instanceof Error ? err.message : String(err);
    } finally {
        manufacturerFiltersSaving.value = false;
    }
}

function manufacturerFilterBadge(row: ManufacturerFilterRow): string {
    const pre = row.plamod_preorder_count;
    const other = row.plamod_other_count;
    if (pre === null && other === null) {
        return '';
    }
    if (other === null) {
        return String(pre ?? 0);
    }
    return `${pre ?? 0} / ${other}`;
}

function showCategory(name: string): void {
    search.value = '';
    page.value = 1;
    categoryFilter.value = [name];
}

function goToNextCategory(): void {
    const next = nextBrowseCategory.value;
    if (next) {
        showCategory(next.category);
    }
}

function goToPreviousCategory(): void {
    const prev = prevBrowseCategory.value;
    if (prev) {
        showCategory(prev.category);
    }
}

function nextCategoryAfterHide(hiding: string[]): string | null {
    const skip = new Set(hiding);
    const list = browseableCategories.value;
    const cursor = browseCursor();
    if (cursor === null) {
        return list.find((facet) => !skip.has(facet.category))?.category ?? null;
    }
    const start = list.findIndex((facet) => facet.category === cursor);
    const from = start >= 0 ? start + 1 : 0;
    for (let i = from; i < list.length; i += 1) {
        const row = list[i];
        if (row && !skip.has(row.category)) {
            return row.category;
        }
    }
    return null;
}

async function hideSelectedCategories(): Promise<void> {
    if (categoryFilter.value.length === 0) {
        return;
    }
    const hiding = [...categoryFilter.value];
    const advanceTo = nextCategoryAfterHide(hiding);
    const next = new Set(excludedCategories.value);
    for (const name of hiding) {
        next.add(name);
    }
    excludedCategories.value = Array.from(next);
    await saveSettings();
    categoryFilter.value = advanceTo ? [advanceTo] : [];
}

async function saveSettings(): Promise<void> {
    settingsSaving.value = true;
    settingsError.value = null;
    try {
        const res = await api.put<{ data: { excluded_categories: string[] } }>(
            '/api/v1/preorders/settings',
            {
                excluded_categories: excludedCategories.value,
            },
        );
        excludedCategories.value = res.data.data?.excluded_categories ?? [];
        await fetchRows();
    } catch (err) {
        settingsError.value = err instanceof Error ? err.message : String(err);
    } finally {
        settingsSaving.value = false;
    }
}

async function loadSyncStatus(): Promise<void> {
    try {
        const res = await api.get<{ data: SyncStatus }>('/api/v1/preorders/sync-status');
        syncStatus.value = res.data.data ?? null;
    } catch (err) {
        syncError.value = err instanceof Error ? err.message : String(err);
    }
}

function extractApiError(err: unknown): string {
    const responseData =
        axios.isAxiosError(err) || (typeof err === 'object' && err !== null && 'response' in err)
            ? ((
                  err as {
                      response?: { data?: { data?: { error_message?: string }; message?: string } };
                  }
              ).response?.data ?? undefined)
            : undefined;
    const msg = responseData?.data?.error_message ?? responseData?.message;
    if (typeof msg === 'string' && msg.trim() !== '') {
        return msg;
    }
    return err instanceof Error ? err.message : String(err);
}

async function refreshFromPlamod(): Promise<void> {
    syncing.value = true;
    syncError.value = null;
    try {
        await api.post('/api/v1/preorders/sync');
        await loadSyncStatus();
        startPolling();
    } catch (err) {
        syncError.value = extractApiError(err);
        await loadSyncStatus();
    } finally {
        syncing.value = false;
    }
}

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, ms);
    });
}

async function pollLiveSearchJob(
    jobId: string,
): Promise<Pick<SearchLinesResult, 'plamod_only' | 'not_found'> & { rows: PreorderRow[] }> {
    for (let attempt = 0; attempt < 400; attempt += 1) {
        const res = await api.post<{
            data: {
                status: string;
                plamod_only: SearchLinesResult['plamod_only'];
                not_found: SearchLinesResult['not_found'];
                rows: PreorderRow[];
                error_summary: string | null;
            };
        }>(
            '/api/v1/preorders/search-lines',
            { phase: 'live_poll', job_id: jobId },
            { timeout: 30_000 },
        );

        const status = res.data.data?.status ?? '';
        if (status === 'completed') {
            return {
                plamod_only: res.data.data?.plamod_only ?? [],
                not_found: res.data.data?.not_found ?? [],
                rows: res.data.data?.rows ?? [],
            };
        }
        if (status === 'failed') {
            throw new Error(res.data.data?.error_summary ?? 'Live Plamod search failed.');
        }
        if (status === 'missing') {
            throw new Error(res.data.data?.error_summary ?? 'Live search job not found.');
        }

        await sleep(LIVE_SEARCH_POLL_MS);
    }

    throw new Error('Live Plamod search timed out while waiting for background job.');
}

function formatSearchError(err: unknown): string {
    if (err && typeof err === 'object' && 'response' in err) {
        const status = (err as { response?: { status?: number } }).response?.status;
        if (status === 524) {
            return 'Search timed out at the network edge (HTTP 524). Live Plamod lookup is retried in small batches; if this persists, try fewer lines.';
        }
    }
    return err instanceof Error ? err.message : String(err);
}

async function searchPasteLines(): Promise<void> {
    pasteSearching.value = true;
    pasteSearchStatus.value = null;
    pasteError.value = null;
    pasteResult.value = null;
    clearPasteGrid();
    const lines = pasteLines.value
        .split(/\r?\n/)
        .map((l) => l.trim())
        .filter((l) => l !== '');
    if (lines.length === 0) {
        pasteSearching.value = false;
        return;
    }
    try {
        pasteSearchStatus.value = `Step 1/2: Matching imported snapshot (${lines.length} lines)…`;
        const snapshotRes = await api.post<{
            data: {
                matched: SearchLinesResult['matched'];
                pending_live: string[];
                rows: PreorderRow[];
            };
        }>('/api/v1/preorders/search-lines', { lines, phase: 'snapshot' }, { timeout: 60_000 });

        const matched = snapshotRes.data.data?.matched ?? [];
        const pendingLive = snapshotRes.data.data?.pending_live ?? [];
        const importedRows = snapshotRes.data.data?.rows ?? [];
        pasteResult.value = { matched, plamod_only: [], not_found: [] };

        if (pendingLive.length === 0) {
            pasteGridRows.value = mergePasteRowsInLineOrder(lines, matched, importedRows, [], []);
            pasteGridActive.value = true;
            pasteSearchStatus.value = `Done — ${pasteGridRows.value.length} row(s) in grid.`;
            return;
        }

        pasteSearchStatus.value = `Step 2/2: Queuing live Plamod search (${pendingLive.length} lines)…`;
        const startRes = await api.post<{ data: { job_id: string; status: string } }>(
            '/api/v1/preorders/search-lines',
            { lines: pendingLive, phase: 'live_start' },
            { timeout: 30_000 },
        );
        const jobId = startRes.data.data?.job_id ?? '';
        if (jobId === '') {
            pasteGridRows.value = mergePasteRowsInLineOrder(lines, matched, importedRows, [], []);
            pasteGridActive.value = true;
            pasteSearchStatus.value = `Done — ${pasteGridRows.value.length} row(s) in grid.`;
            return;
        }

        pasteSearchStatus.value = `Step 2/2: Searching Plamod live (${pendingLive.length} lines in background)…`;
        const live = await pollLiveSearchJob(jobId);
        pasteResult.value = {
            matched,
            plamod_only: live.plamod_only,
            not_found: live.not_found,
        };
        pasteGridRows.value = mergePasteRowsInLineOrder(
            lines,
            matched,
            importedRows,
            live.plamod_only,
            live.rows,
        );
        pasteGridActive.value = true;

        pasteSearchStatus.value = `Done — ${pasteGridRows.value.length} in grid (${matched.length} imported, ${live.plamod_only.length} on Plamod only, ${live.not_found.length} not found).`;
    } catch (err) {
        pasteError.value = formatSearchError(err);
    } finally {
        pasteSearching.value = false;
    }
}

const selectableRows = computed(() => displayRows.value.filter((row) => isSelectable(row)));

const selectedCount = computed(() => selectedSkus.value.length);

const allSelectableChecked = computed(
    () =>
        selectableRows.value.length > 0 &&
        selectableRows.value.every((row) => selectedSkus.value.includes(row.sku)),
);

function selectedRows(): PreorderRow[] {
    const bySku = new Map(displayRows.value.map((row) => [row.sku, row]));
    return selectedSkus.value
        .map((sku) => bySku.get(sku))
        .filter((row): row is PreorderRow => row !== undefined);
}

function isSelectable(row: PreorderRow): boolean {
    return (
        row.store_preorder_status !== 'open' &&
        !row.plamod_in_stock &&
        !row.plamod_preorder_closed &&
        !row.plamod_stock_listing
    );
}

function pruneSelectedSkus(): void {
    const visible = new Set(displayRows.value.map((row) => row.sku));
    selectedSkus.value = selectedSkus.value.filter((sku) => visible.has(sku));
}

function toggleSku(sku: string, checked: boolean): void {
    if (checked) {
        if (!selectedSkus.value.includes(sku)) {
            selectedSkus.value = [...selectedSkus.value, sku];
        }
        return;
    }

    selectedSkus.value = selectedSkus.value.filter((value) => value !== sku);
}

function toggleAllSelectable(checked: boolean): void {
    if (!checked) {
        const selectable = new Set(selectableRows.value.map((row) => row.sku));
        selectedSkus.value = selectedSkus.value.filter((sku) => !selectable.has(sku));
        return;
    }

    const next = new Set(selectedSkus.value);
    for (const row of selectableRows.value) {
        next.add(row.sku);
    }
    selectedSkus.value = [...next];
}

async function setInterest(skus: string[], notInterested: boolean): Promise<void> {
    const unique = [...new Set(skus.map((sku) => sku.trim()).filter((sku) => sku !== ''))];
    if (unique.length === 0 || interestBusy.value) {
        return;
    }
    interestBusy.value = true;
    interestMessage.value = null;
    errorMessage.value = null;
    try {
        const res = await api.post<{ requested: number; updated: number; not_interested: boolean }>(
            '/api/v1/preorders/interest',
            { skus: unique, not_interested: notInterested },
        );
        const updated = Number(res.data.updated ?? 0);
        interestMessage.value = notInterested
            ? `Marked ${updated} kit${updated === 1 ? '' : 's'} not interested.`
            : `Marked ${updated} kit${updated === 1 ? '' : 's'} interested again.`;
        selectedSkus.value = selectedSkus.value.filter((sku) => !unique.includes(sku));
        await fetchRows();
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        interestBusy.value = false;
    }
}

function markSelectedNotInterested(): void {
    void setInterest(selectedSkus.value, interest.value !== 'not_interested');
}

function openStorePreorderDialog(): void {
    if (selectedCount.value === 0) {
        return;
    }
    const prices: Record<string, string> = {};
    const deposits: Record<string, string> = {};
    const caps: Record<string, string> = {};
    const closings: Record<string, string> = {};
    for (const row of selectedRows()) {
        prices[row.sku] = row.unit_selling_price ?? '';
        deposits[row.sku] = openDefaultDepositPercent.value;
        caps[row.sku] = '';
        closings[row.sku] = dayBeforeYmd(row.po_due_date);
    }
    openPriceBySku.value = prices;
    openDepositBySku.value = deposits;
    openCapBySku.value = caps;
    openClosingBySku.value = closings;
    openError.value = null;
    openDialogOpen.value = true;
}

function patchOpenField(
    target: { value: Record<string, string> },
    sku: string,
    value: string,
): void {
    target.value = { ...target.value, [sku]: value };
}

function setOpenPrice(sku: string, value: string): void {
    patchOpenField(openPriceBySku, sku, value);
}

function setOpenDeposit(sku: string, value: string): void {
    patchOpenField(openDepositBySku, sku, value);
}

function setOpenCap(sku: string, value: string): void {
    patchOpenField(openCapBySku, sku, value);
}

function setOpenClosing(sku: string, value: string): void {
    patchOpenField(openClosingBySku, sku, value);
}

async function confirmOpenStorePreorders(): Promise<void> {
    openBusy.value = true;
    openError.value = null;
    try {
        const res = await api.post<{
            data: unknown[];
            opened: number;
            reopened: number;
            skipped_open: number;
            products_created: number;
            shopify_queued: number;
            photos_queued: number;
        }>('/api/v1/store-preorders', {
            items: selectedRows().map((row) => {
                const capRaw = (openCapBySku.value[row.sku] ?? '').trim();
                return {
                    sku: row.sku,
                    selling_price: openPriceBySku.value[row.sku] || null,
                    deposit_percent: Number(
                        openDepositBySku.value[row.sku] ?? openDefaultDepositPercent.value,
                    ),
                    cap_qty: capRaw === '' ? null : Number(capRaw),
                    window_ends_on: (openClosingBySku.value[row.sku] ?? '').trim() || null,
                };
            }),
        });
        const opened = Number(res.data.opened ?? 0);
        const reopened = Number(res.data.reopened ?? 0);
        const created = Number(res.data.products_created ?? 0);
        const shopifyQueued = Number(res.data.shopify_queued ?? 0);
        const photosQueued = Number(res.data.photos_queued ?? 0);
        openResultMessage.value = `Opened ${opened}${reopened > 0 ? `, reopened ${reopened}` : ''}. ERP products created: ${created}. Shopify publish queued: ${shopifyQueued}. Photo crawls: ${photosQueued}.`;
        selectedSkus.value = [];
        openDialogOpen.value = false;
        await fetchRows();
    } catch (err) {
        openError.value = extractApiError(err);
    } finally {
        openBusy.value = false;
    }
}

watch(
    [newOnly, futureReleasesOnly, perPage, storeOffer, interest, includeClosed, categoryFilter],
    async () => {
        page.value = 1;
        await fetchRows();
    },
);

watch([sort, sortDir], async () => {
    page.value = 1;
    if (pasteGridActive.value) {
        return;
    }
    await fetchRows();
});

onMounted(async () => {
    await Promise.all([
        fetchRows(),
        loadSettings(),
        loadCatalogPricing(),
        loadShippingEstimate(),
        loadManufacturerFilters(),
        loadSyncStatus(),
    ]);
    if (syncBusy.value) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
                    Plamod preorders
                </h1>
                <p class="text-sm text-slate-600">
                    Live Plamod preorders only (preorder price + open window, not current in-stock).
                    Not customer store pre-orders. Select live kits or figures and open them as
                    store preorders.
                    <span v-if="syncStatus?.finished_at" class="ml-2 text-slate-500">
                        Last sync: {{ formatTorontoDateTime(syncStatus.finished_at) }}
                        · Auto-refresh 6:00 AM daily
                    </span>
                    <span
                        v-if="syncPhaseLabel && syncProgressPercent === null"
                        class="ml-2 font-medium text-amber-700"
                    >
                        {{ syncPhaseLabel }}
                    </span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a
                    href="/store-preorders"
                    class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 hover:bg-slate-100"
                >
                    View store preorders
                </a>
                <button
                    type="button"
                    class="h-9 rounded-md border border-emerald-200 bg-emerald-50 px-3 text-sm font-medium text-emerald-900 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="selectedCount === 0 || openBusy"
                    data-testid="preorders-open-store"
                    @click="openStorePreorderDialog"
                >
                    Open & push to Shopify{{ selectedCount > 0 ? ` (${selectedCount})` : '' }}
                </button>
                <button
                    type="button"
                    class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="selectedCount === 0 || interestBusy"
                    data-testid="preorders-not-interested"
                    @click="markSelectedNotInterested"
                >
                    {{
                        interest === 'not_interested'
                            ? `Mark interested${selectedCount > 0 ? ` (${selectedCount})` : ''}`
                            : `Not interested${selectedCount > 0 ? ` (${selectedCount})` : ''}`
                    }}
                </button>
                <button
                    type="button"
                    class="h-9 rounded-md bg-slate-900 px-3 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="syncBusy"
                    data-testid="preorders-refresh"
                    @click="refreshFromPlamod"
                >
                    {{ syncBusy ? 'Refreshing…' : 'Refresh from Plamod' }}
                </button>
            </div>
        </div>

        <div
            v-if="syncBusy && syncProgressPercent !== null"
            class="rounded-lg border border-amber-200 bg-amber-50/70 p-3"
            data-testid="preorders-sync-progress"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-amber-900">
                <span class="font-medium">{{ syncPhaseLabel }}</span>
                <span v-if="syncProgressDetail" class="text-amber-800">{{
                    syncProgressDetail
                }}</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-amber-100">
                <div
                    class="h-full rounded-full bg-amber-500 transition-all duration-500"
                    :style="{ width: `${syncProgressPercent}%` }"
                />
            </div>
        </div>

        <p v-if="syncError" class="text-sm text-red-700">{{ syncError }}</p>
        <p
            v-if="syncStatus?.status === 'failed' && syncStatus.error_summary"
            class="text-sm text-red-700"
        >
            Sync failed: {{ syncStatus.error_summary }}
        </p>
        <div
            v-if="syncManufacturerFailureLabel || syncFailureSummary.length > 0"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
            data-testid="preorders-sync-failures"
        >
            <p v-if="syncManufacturerFailureLabel" class="font-medium">
                {{ syncManufacturerFailureLabel }}
            </p>
            <ul v-if="syncFailureSummary.length > 0" class="mt-2 list-disc space-y-1 pl-5">
                <li
                    v-for="row in syncFailureSummary.slice(0, 6)"
                    :key="`${row.error_kind}-${row.error_message}`"
                >
                    {{ row.count }}× {{ row.error_kind }} — {{ row.error_message }}
                </li>
            </ul>
            <p
                v-if="syncStatus?.counts?.failure_log_path"
                class="mt-2 font-mono text-xs text-amber-800"
            >
                Failure log: {{ syncStatus.counts.failure_log_path }}
            </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-800">Settings</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[280px]">
                    <MultiSelectFilter
                        v-model="excludedCategories"
                        label="Excluded categories"
                        :options="excludedCategoryOptions"
                        placeholder="Hide categories from table…"
                        test-id="preorders-excluded-categories"
                    />
                </div>
                <button
                    type="button"
                    class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:opacity-60"
                    :disabled="settingsSaving"
                    @click="saveSettings"
                >
                    {{ settingsSaving ? 'Saving…' : 'Save settings' }}
                </button>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Use Next category to walk one live category at a time, then hide it from the pick
                list if you do not want those kits.
            </p>
            <p v-if="settingsError" class="mt-2 text-sm text-red-700">{{ settingsError }}</p>
        </div>

        <details class="rounded-lg border border-slate-200 bg-white p-4">
            <summary class="cursor-pointer text-sm font-semibold text-slate-800">
                Bandai manufacturer series (not used by current kits-hub sync)
            </summary>
            <div class="mb-3 mt-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs text-slate-600">
                        Leftover from manufacturer crawls. Refresh currently uses the Plastic Model
                        Kits hub (New Preorders + Offer Sheets), not these include/exclude
                        decisions.
                    </p>
                </div>
                <button
                    type="button"
                    class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:opacity-60"
                    :disabled="manufacturerFiltersDiscovering || syncBusy"
                    data-testid="preorders-discover-manufacturer-filters"
                    @click="discoverManufacturerFilters"
                >
                    {{ manufacturerFiltersDiscovering ? 'Discovering…' : 'Refresh series list' }}
                </button>
            </div>

            <p v-if="manufacturerFiltersLoading" class="text-sm text-slate-600">
                Loading manufacturer filters…
            </p>
            <p v-if="manufacturerFiltersError" class="text-sm text-red-700">
                {{ manufacturerFiltersError }}
            </p>

            <div v-if="manufacturerFilters" class="grid gap-4 lg:grid-cols-3">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-700">
                        Not decided ({{ manufacturerFilters.counts.undecided }})
                    </h3>
                    <ul class="mt-2 max-h-64 space-y-2 overflow-y-auto text-sm">
                        <li
                            v-for="row in manufacturerFilters.undecided"
                            :key="row.id"
                            class="rounded border border-amber-100 bg-amber-50/40 px-2 py-1.5"
                        >
                            <div class="font-medium text-slate-900">{{ row.name }}</div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-600"
                            >
                                <span>{{
                                    row.filter_type === 'category_line' ? 'Category line' : 'Series'
                                }}</span>
                                <span v-if="manufacturerFilterBadge(row)"
                                    >Preorder {{ manufacturerFilterBadge(row) }}</span
                                >
                            </div>
                            <div class="mt-1 flex gap-1">
                                <button
                                    type="button"
                                    class="rounded border border-emerald-300 px-2 py-0.5 text-xs text-emerald-800 hover:bg-emerald-50 disabled:opacity-60"
                                    :disabled="manufacturerFiltersSaving"
                                    @click="setManufacturerFilterDecision(row, 'include')"
                                >
                                    Include
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-60"
                                    :disabled="manufacturerFiltersSaving"
                                    @click="setManufacturerFilterDecision(row, 'exclude')"
                                >
                                    Exclude
                                </button>
                            </div>
                        </li>
                        <li
                            v-if="manufacturerFilters.undecided.length === 0"
                            class="text-slate-500"
                        >
                            None
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-emerald-700">
                        Included ({{ manufacturerFilters.counts.include }})
                    </h3>
                    <ul class="mt-2 max-h-64 space-y-2 overflow-y-auto text-sm">
                        <li
                            v-for="row in manufacturerFilters.include"
                            :key="row.id"
                            class="rounded border border-emerald-100 bg-emerald-50/40 px-2 py-1.5"
                        >
                            <div class="font-medium text-slate-900">{{ row.name }}</div>
                            <div class="mt-0.5 text-xs text-slate-600">
                                <span>{{
                                    row.filter_type === 'category_line' ? 'Category line' : 'Series'
                                }}</span>
                                <span v-if="manufacturerFilterBadge(row)" class="ml-2"
                                    >Preorder {{ manufacturerFilterBadge(row) }}</span
                                >
                            </div>
                            <div class="mt-1 flex gap-1">
                                <button
                                    type="button"
                                    class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-60"
                                    :disabled="manufacturerFiltersSaving"
                                    @click="setManufacturerFilterDecision(row, 'undecided')"
                                >
                                    Undecided
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-60"
                                    :disabled="manufacturerFiltersSaving"
                                    @click="setManufacturerFilterDecision(row, 'exclude')"
                                >
                                    Exclude
                                </button>
                            </div>
                        </li>
                        <li v-if="manufacturerFilters.include.length === 0" class="text-slate-500">
                            None
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Excluded ({{ manufacturerFilters.counts.exclude }})
                    </h3>
                    <ul class="mt-2 max-h-64 space-y-2 overflow-y-auto text-sm">
                        <li
                            v-for="row in manufacturerFilters.exclude"
                            :key="row.id"
                            class="rounded border border-slate-200 bg-slate-50 px-2 py-1.5"
                        >
                            <div class="font-medium text-slate-900">{{ row.name }}</div>
                            <div class="mt-0.5 text-xs text-slate-600">
                                <span>{{
                                    row.filter_type === 'category_line' ? 'Category line' : 'Series'
                                }}</span>
                                <span v-if="manufacturerFilterBadge(row)" class="ml-2"
                                    >Preorder {{ manufacturerFilterBadge(row) }}</span
                                >
                            </div>
                            <div class="mt-1 flex gap-1">
                                <button
                                    type="button"
                                    class="rounded border border-emerald-300 px-2 py-0.5 text-xs text-emerald-800 hover:bg-emerald-50 disabled:opacity-60"
                                    :disabled="manufacturerFiltersSaving"
                                    @click="setManufacturerFilterDecision(row, 'include')"
                                >
                                    Include
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-60"
                                    :disabled="manufacturerFiltersSaving"
                                    @click="setManufacturerFilterDecision(row, 'undecided')"
                                >
                                    Undecided
                                </button>
                            </div>
                        </li>
                        <li v-if="manufacturerFilters.exclude.length === 0" class="text-slate-500">
                            None
                        </li>
                    </ul>
                </div>
            </div>
        </details>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-800">Multi-line search</h2>
            <textarea
                v-model="pasteLines"
                rows="4"
                placeholder="Paste SKUs, barcodes, or product names (one per line)…"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:opacity-60"
                    :disabled="pasteSearching"
                    data-testid="preorders-search-lines"
                    @click="searchPasteLines"
                >
                    {{ pasteSearching ? 'Searching…' : 'Search lines' }}
                </button>
                <p
                    v-if="pasteSearchStatus"
                    class="text-sm text-slate-600"
                    data-testid="preorders-search-status"
                >
                    {{ pasteSearchStatus }}
                </p>
            </div>
            <p v-if="pasteError" class="mt-2 text-sm text-red-700">{{ pasteError }}</p>
            <div v-if="pasteResult && pasteResult.not_found.length > 0" class="mt-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-red-700">
                    Not found
                </h3>
                <ul class="mt-1 space-y-1 text-sm text-red-700">
                    <li v-for="nf in pasteResult.not_found" :key="nf">{{ nf }}</li>
                </ul>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label for="preorders-search" class="text-xs font-medium text-slate-600"
                    >Search</label
                >
                <input
                    id="preorders-search"
                    v-model="search"
                    type="text"
                    placeholder="SKU, barcode, name…"
                    class="h-9 w-[280px] rounded-md border border-slate-300 bg-white px-2 text-sm"
                    @keydown.enter.prevent="fetchRows"
                />
            </div>
            <button
                type="button"
                class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:opacity-60"
                :disabled="loading"
                @click="fetchRows"
            >
                {{ loading ? 'Loading…' : 'Search' }}
            </button>
            <label
                class="flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm"
            >
                <input
                    v-model="newOnly"
                    type="checkbox"
                    class="rounded border-slate-300"
                    title="SKUs that are not in the ERP catalog yet (no non-archived product with that SKU). Same as the New badge."
                />
                New only
            </label>
            <label
                class="flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm"
            >
                <input
                    v-model="futureReleasesOnly"
                    type="checkbox"
                    class="rounded border-slate-300"
                    title="Release date is today or later (America/Toronto). Kits with no release date are hidden."
                />
                Future releases only
            </label>
            <label
                class="flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm"
            >
                <input v-model="includeClosed" type="checkbox" class="rounded border-slate-300" />
                Show leftovers (stock / closed / in-stock)
            </label>
            <div class="min-w-[220px]">
                <MultiSelectFilter
                    v-model="categoryFilter"
                    label="Category"
                    :options="categoryFilterOptions"
                    placeholder="All categories"
                    test-id="preorders-category-filter"
                />
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-slate-600">Walk categories</span>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="prevBrowseCategory === null || loading"
                        data-testid="preorders-prev-category"
                        :title="
                            prevBrowseCategory
                                ? prevBrowseCategory.category
                                : 'No previous category'
                        "
                        @click="goToPreviousCategory"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="nextBrowseCategory === null || loading"
                        data-testid="preorders-next-category"
                        :title="
                            nextBrowseCategory
                                ? `${nextBrowseCategory.category} · ${kitCountLabel(nextBrowseCategory.count)}`
                                : 'No next category'
                        "
                        @click="goToNextCategory"
                    >
                        {{
                            nextBrowseCategory
                                ? `Next: ${nextBrowseCategory.category}`
                                : 'Next category'
                        }}
                    </button>
                </div>
            </div>
            <button
                type="button"
                class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="categoryFilter.length === 0 || settingsSaving"
                data-testid="preorders-hide-selected-categories"
                @click="hideSelectedCategories"
            >
                {{
                    categoryFilter.length === 1 ? 'Hide this category' : 'Hide selected categories'
                }}
            </button>
            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                Store offer
                <select
                    v-model="storeOffer"
                    class="h-9 rounded-md border border-slate-300 bg-white px-2 text-sm font-normal text-slate-800"
                    data-testid="preorders-store-offer"
                >
                    <option value="not_opened">Not opened</option>
                    <option value="opened">Already opened</option>
                    <option value="all">All kits</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                Interest
                <select
                    v-model="interest"
                    class="h-9 rounded-md border border-slate-300 bg-white px-2 text-sm font-normal text-slate-800"
                    data-testid="preorders-interest"
                >
                    <option value="interested">Interested</option>
                    <option value="not_interested">Not interested</option>
                    <option value="all">All kits</option>
                </select>
            </label>
        </div>
        <p class="text-sm text-slate-600" data-testid="preorders-category-walk-status">
            {{ browsePositionLabel }}
        </p>
        <p
            v-if="interestMessage"
            class="text-sm text-slate-700"
            data-testid="preorders-interest-message"
        >
            {{ interestMessage }}
        </p>
        <p v-if="openResultMessage" class="text-sm text-emerald-800">
            {{ openResultMessage }}
            <a href="/store-preorders" class="ml-1 font-medium text-blue-700 underline"
                >View store preorders</a
            >
        </p>
        <p v-if="openError" class="text-sm text-red-700">{{ openError }}</p>

        <p v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</p>
        <p v-if="pasteGridActive" class="text-sm text-slate-600">
            Showing multi-line search results ({{ pasteGridRows.length }} rows).
            <button
                type="button"
                class="ml-1 text-blue-700 underline"
                @click="
                    clearPasteGrid();
                    fetchRows();
                "
            >
                Clear and show all preorders
            </button>
        </p>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-left text-sm">
                <thead
                    class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600"
                >
                    <tr>
                        <th class="px-3 py-2">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300"
                                :checked="allSelectableChecked"
                                :disabled="selectableRows.length === 0"
                                data-testid="preorders-select-all"
                                @change="
                                    toggleAllSelectable(($event.target as HTMLInputElement).checked)
                                "
                            />
                        </th>
                        <th class="px-3 py-2">Image</th>
                        <SortableTh
                            label="Product"
                            :active="sort === 'name'"
                            :dir="sortDir"
                            test-id="preorders-sort-name"
                            @sort="togglePreorderSort('name')"
                        />
                        <SortableTh
                            label="Release"
                            :active="sort === 'release'"
                            :dir="sortDir"
                            test-id="preorders-sort-release"
                            @sort="togglePreorderSort('release')"
                        />
                        <th class="px-3 py-2">
                            <div class="flex flex-col items-start gap-0.5">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 uppercase tracking-wide hover:underline"
                                    :class="
                                        sort === 'eta'
                                            ? 'font-semibold text-slate-900'
                                            : 'font-medium text-slate-600'
                                    "
                                    data-testid="preorders-sort-eta"
                                    :aria-sort="
                                        sort === 'eta'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : 'none'
                                    "
                                    @click="togglePreorderSort('eta')"
                                >
                                    ETA
                                    <span v-if="sort === 'eta'" aria-hidden="true">{{
                                        sortDir === 'asc' ? '▲' : '▼'
                                    }}</span>
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-[10px] uppercase tracking-wide hover:underline"
                                    :class="
                                        sort === 'eta_months'
                                            ? 'font-semibold text-slate-900'
                                            : 'font-medium text-slate-500'
                                    "
                                    data-testid="preorders-sort-eta-months"
                                    :aria-sort="
                                        sort === 'eta_months'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : 'none'
                                    "
                                    @click="togglePreorderSort('eta_months')"
                                >
                                    Mo
                                    <span v-if="sort === 'eta_months'" aria-hidden="true">{{
                                        sortDir === 'asc' ? '▲' : '▼'
                                    }}</span>
                                </button>
                            </div>
                        </th>
                        <SortableTh
                            label="Category"
                            :active="sort === 'category'"
                            :dir="sortDir"
                            test-id="preorders-sort-category"
                            @sort="togglePreorderSort('category')"
                        />
                        <SortableTh
                            label="Stock $"
                            :active="sort === 'stock'"
                            :dir="sortDir"
                            test-id="preorders-sort-stock"
                            @sort="togglePreorderSort('stock')"
                        />
                        <SortableTh
                            label="Sell $"
                            :active="sort === 'sell'"
                            :dir="sortDir"
                            test-id="preorders-sort-sell"
                            @sort="togglePreorderSort('sell')"
                        />
                        <SortableTh
                            label="PO qty"
                            :active="sort === 'qty'"
                            :dir="sortDir"
                            test-id="preorders-sort-qty"
                            @sort="togglePreorderSort('qty')"
                        />
                        <SortableTh
                            label="PO due"
                            :active="sort === 'closing'"
                            :dir="sortDir"
                            test-id="preorders-sort-closing"
                            @sort="togglePreorderSort('closing')"
                        />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in displayRows"
                        :key="row.sku"
                        class="border-b border-slate-100 hover:bg-slate-50"
                    >
                        <td class="px-3 py-2 align-middle">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300"
                                :checked="selectedSkus.includes(row.sku)"
                                :disabled="!isSelectable(row)"
                                :data-testid="`preorders-open-${row.sku}`"
                                @change="
                                    toggleSku(row.sku, ($event.target as HTMLInputElement).checked)
                                "
                            />
                        </td>
                        <td class="px-3 py-2 align-middle">
                            <img
                                v-if="row.image_url"
                                :src="row.image_url"
                                :alt="row.product_name"
                                class="h-20 w-20 rounded border border-slate-200 bg-white object-contain"
                                loading="lazy"
                            />
                            <span
                                v-else
                                class="inline-flex h-20 w-20 items-center justify-center rounded border border-dashed border-slate-200 text-[10px] text-slate-400"
                            >
                                {{ row.image_download_status }}
                            </span>
                        </td>
                        <td class="max-w-[28rem] px-3 py-2 align-middle">
                            <div class="font-medium text-slate-900">{{ row.product_name }}</div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600"
                            >
                                <a
                                    :href="row.plamod_pdp_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-mono text-blue-700 hover:underline"
                                >
                                    {{ row.sku }}
                                </a>
                                <span v-if="row.barcode" class="font-mono">{{ row.barcode }}</span>
                                <span
                                    v-if="row.is_new"
                                    class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-800"
                                >
                                    New
                                </span>
                                <span
                                    v-if="row.not_interested"
                                    class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-700"
                                >
                                    Not interested
                                </span>
                                <span
                                    v-if="row.not_in_import"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900"
                                >
                                    Plamod only
                                </span>
                                <span
                                    v-if="row.store_preorder_status === 'open'"
                                    class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-sky-800"
                                >
                                    Store open
                                </span>
                                <span
                                    v-else-if="row.store_preorder_status === 'closed'"
                                    class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-700"
                                >
                                    Store closed
                                </span>
                                <span
                                    v-if="row.plamod_in_stock"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900"
                                >
                                    In stock now
                                </span>
                                <span
                                    v-if="row.plamod_preorder_closed"
                                    class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-700"
                                >
                                    Preorder closed
                                </span>
                                <span
                                    v-if="row.plamod_stock_listing"
                                    class="rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-orange-900"
                                >
                                    Stock listing
                                </span>
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                <span v-if="row.series">{{ row.series }}</span>
                                <span v-if="row.series && row.manufacturer"> · </span>
                                <span v-if="row.manufacturer">{{ row.manufacturer }}</span>
                                <span v-if="!row.series && !row.manufacturer">—</span>
                            </div>
                            <button
                                type="button"
                                class="mt-1 text-xs font-medium text-slate-600 underline decoration-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="interestBusy"
                                :data-testid="`preorders-interest-${row.sku}`"
                                @click="setInterest([row.sku], !row.not_interested)"
                            >
                                {{ row.not_interested ? 'Mark interested' : 'Not interested' }}
                            </button>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.release_date ? formatTorontoDate(row.release_date) : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            <div>{{ row.eta_date ? formatTorontoDate(row.eta_date) : '—' }}</div>
                            <div v-if="etaLeadMonthsLabel(row)" class="text-xs text-slate-500">
                                {{ etaLeadMonthsLabel(row) }}
                            </div>
                        </td>
                        <td class="px-3 py-2 align-middle">{{ row.category ?? '—' }}</td>
                        <td class="px-3 py-2 align-middle">
                            {{ formatMoney2OrEmpty(row.price_preorder ?? row.price_stock) }}
                        </td>
                        <td class="px-3 py-2 align-middle font-medium">
                            {{ formatMoney2OrEmpty(row.unit_selling_price) }}
                        </td>
                        <td class="px-3 py-2 align-middle">{{ row.quantity_preorder ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.po_due_date ? formatTorontoDate(row.po_due_date) : '—' }}
                        </td>
                    </tr>
                    <tr v-if="!loading && displayRows.length === 0">
                        <td colspan="10" class="px-3 py-8 text-center text-slate-500">
                            No preorders found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="meta && !pasteGridActive"
            class="flex items-center justify-between text-sm text-slate-600"
        >
            <span>
                Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} rows
            </span>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
                    :disabled="page <= 1 || loading"
                    @click="
                        page--;
                        fetchRows();
                    "
                >
                    Prev
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
                    :disabled="!meta || page >= meta.last_page || loading"
                    @click="
                        page++;
                        fetchRows();
                    "
                >
                    Next
                </button>
            </div>
        </div>

        <OpenStorePreorderDialog
            :open="openDialogOpen"
            :count="selectedCount"
            :items="selectedRows()"
            :price-by-sku="openPriceBySku"
            :deposit-by-sku="openDepositBySku"
            :cap-by-sku="openCapBySku"
            :closing-by-sku="openClosingBySku"
            :default-deposit-percent="openDefaultDepositPercent"
            :shipping-percent="openShippingPercent"
            :busy="openBusy"
            @update:price="setOpenPrice"
            @update:deposit="setOpenDeposit"
            @update:cap="setOpenCap"
            @update:closing="setOpenClosing"
            @confirm="confirmOpenStorePreorders"
            @cancel="openDialogOpen = false"
        />
    </div>
</template>

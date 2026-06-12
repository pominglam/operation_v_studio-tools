<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import axios from 'axios';
import { api } from '../lib/api';
import { formatTorontoDate, formatTorontoDateTime } from '../lib/datetime';
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
    is_new: boolean;
    not_in_import: boolean;
    image_url: string | null;
    image_download_status: string;
    plamod_pdp_url: string;
};

type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        categories?: string[];
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
const categories = ref<string[]>([]);
const loading = ref(false);
const errorMessage = ref<string | null>(null);

const search = ref('');
const newOnly = ref(false);
const page = ref(1);
const perPage = ref(50);

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

const displayRows = computed(() => (pasteGridActive.value ? pasteGridRows.value : rows.value));

const LIVE_SEARCH_POLL_MS = 3000;
const MANUFACTURER_DISCOVER_POLL_MS = 3000;

let pollTimer: number | null = null;

const excludedCategoryOptions = computed<MultiSelectOption[]>(() =>
    categories.value.map((c) => ({ value: c, label: c })),
);

const syncBusy = computed(
    () =>
        syncing.value
        || ['queued', 'running'].includes(syncStatus.value?.status ?? ''),
);

const syncAutoResumeAttempt = computed(() => Number(syncStatus.value?.counts?.auto_resume_attempt ?? 0));

const syncFailureSummary = computed((): FailureSummaryRow[] => {
    const raw = syncStatus.value?.counts?.failure_summary;
    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter(
        (row): row is FailureSummaryRow =>
            typeof row === 'object'
            && row !== null
            && typeof (row as FailureSummaryRow).error_kind === 'string'
            && typeof (row as FailureSummaryRow).error_message === 'string'
            && typeof (row as FailureSummaryRow).count === 'number',
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
        return 'Exporting hub preorders…';
    }
    if (phase === 'manufacturer_export' || phase === 'manufacturer_recovery') {
        const processed = Number(counts.manufacturer_filters_processed ?? 0);
        const total = Number(counts.manufacturer_filters_total ?? 0);
        const current = String(counts.manufacturer_current_filter ?? '').trim();
        const succeeded = Number(counts.manufacturer_export_succeeded ?? 0);
        const failed = Number(counts.manufacturer_export_failed ?? 0);
        const prefix =
            phase === 'manufacturer_recovery' ? 'Retrying failed filters' : 'Exporting manufacturer filters';
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
    const liveByLine = new Map(
        plamodOnly.map((hit, index) => [hit.line, liveRows[index] ?? null]),
    );

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
            },
        });
        rows.value = res.data.data ?? [];
        meta.value = res.data.meta ?? null;
        if (res.data.meta?.categories) {
            categories.value = res.data.meta.categories;
        }
    } catch (err) {
        if (!opts?.silent) {
            errorMessage.value = err instanceof Error ? err.message : String(err);
        }
    } finally {
        if (!opts?.silent) {
            loading.value = false;
        }
    }
}

async function loadSettings(): Promise<void> {
    try {
        const res = await api.get<{ data: { excluded_categories: string[] } }>('/api/v1/preorders/settings');
        excludedCategories.value = res.data.data?.excluded_categories ?? [];
    } catch (err) {
        settingsError.value = err instanceof Error ? err.message : String(err);
    }
}

async function loadManufacturerFilters(): Promise<void> {
    manufacturerFiltersLoading.value = true;
    manufacturerFiltersError.value = null;
    try {
        const res = await api.get<{ data: ManufacturerFiltersGrouped }>('/api/v1/preorders/manufacturer-filters');
        manufacturerFilters.value = res.data.data ?? null;
    } catch (err) {
        manufacturerFiltersError.value = err instanceof Error ? err.message : String(err);
    } finally {
        manufacturerFiltersLoading.value = false;
    }
}

async function pollManufacturerFilterDiscoverJob(jobId: string): Promise<ManufacturerFiltersGrouped> {
    for (let attempt = 0; attempt < 400; attempt += 1) {
        const res = await api.post<{
            data: {
                status: string;
                ok?: boolean;
                filters?: ManufacturerFiltersGrouped;
                error_message?: string | null;
            };
        }>('/api/v1/preorders/manufacturer-filters/discover', { job_id: jobId }, { timeout: 30_000 });

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
        const res = await api.put<{ data: ManufacturerFiltersGrouped }>('/api/v1/preorders/manufacturer-filters', {
            updates: [{ id: row.id, decision }],
        });
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

async function saveSettings(): Promise<void> {
    settingsSaving.value = true;
    settingsError.value = null;
    try {
        const res = await api.put<{ data: { excluded_categories: string[] } }>('/api/v1/preorders/settings', {
            excluded_categories: excludedCategories.value,
        });
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
            ? ((err as { response?: { data?: { data?: { error_message?: string }; message?: string } } }).response
                  ?.data ?? undefined)
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
        }>('/api/v1/preorders/search-lines', { phase: 'live_poll', job_id: jobId }, { timeout: 30_000 });

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

watch([newOnly, perPage], async () => {
    page.value = 1;
    await fetchRows();
});

onMounted(async () => {
    await Promise.all([fetchRows(), loadSettings(), loadManufacturerFilters(), loadSyncStatus()]);
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
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Plamod Preorders</h1>
                <p class="text-sm text-slate-600">
                    New preorders from Plamod retailer portal.
                    <span v-if="syncStatus?.finished_at" class="ml-2 text-slate-500">
                        Last sync: {{ formatTorontoDateTime(syncStatus.finished_at) }}
                    </span>
                    <span v-if="syncPhaseLabel && syncProgressPercent === null" class="ml-2 font-medium text-amber-700">
                        {{ syncPhaseLabel }}
                    </span>
                </p>
            </div>

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

        <div
            v-if="syncBusy && syncProgressPercent !== null"
            class="rounded-lg border border-amber-200 bg-amber-50/70 p-3"
            data-testid="preorders-sync-progress"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-amber-900">
                <span class="font-medium">{{ syncPhaseLabel }}</span>
                <span v-if="syncProgressDetail" class="text-amber-800">{{ syncProgressDetail }}</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-amber-100">
                <div
                    class="h-full rounded-full bg-amber-500 transition-all duration-500"
                    :style="{ width: `${syncProgressPercent}%` }"
                />
            </div>
        </div>

        <p v-if="syncError" class="text-sm text-red-700">{{ syncError }}</p>
        <p v-if="syncStatus?.status === 'failed' && syncStatus.error_summary" class="text-sm text-red-700">
            Sync failed: {{ syncStatus.error_summary }}
        </p>
        <div
            v-if="syncManufacturerFailureLabel || syncFailureSummary.length > 0"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
            data-testid="preorders-sync-failures"
        >
            <p v-if="syncManufacturerFailureLabel" class="font-medium">{{ syncManufacturerFailureLabel }}</p>
            <ul v-if="syncFailureSummary.length > 0" class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="row in syncFailureSummary.slice(0, 6)" :key="`${row.error_kind}-${row.error_message}`">
                    {{ row.count }}× {{ row.error_kind }} — {{ row.error_message }}
                </li>
            </ul>
            <p v-if="syncStatus?.counts?.failure_log_path" class="mt-2 font-mono text-xs text-amber-800">
                Failure log: {{ syncStatus.counts.failure_log_path }}
            </p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-800">Settings</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[280px]">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Excluded categories</label>
                    <MultiSelectFilter
                        v-model="excludedCategories"
                        :options="excludedCategoryOptions"
                        placeholder="Hide categories from table…"
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
            <p v-if="settingsError" class="mt-2 text-sm text-red-700">{{ settingsError }}</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-slate-800">Bandai manufacturer series</h2>
                    <p class="mt-1 text-xs text-slate-600">
                        Choose which BANDAI HOBBY SERIES (and SD category lines) to pull on refresh. Undecided
                        filters are skipped until you include them.
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

            <p v-if="manufacturerFiltersLoading" class="text-sm text-slate-600">Loading manufacturer filters…</p>
            <p v-if="manufacturerFiltersError" class="text-sm text-red-700">{{ manufacturerFiltersError }}</p>

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
                            <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                                <span>{{ row.filter_type === 'category_line' ? 'Category line' : 'Series' }}</span>
                                <span v-if="manufacturerFilterBadge(row)">Preorder {{ manufacturerFilterBadge(row) }}</span>
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
                        <li v-if="manufacturerFilters.undecided.length === 0" class="text-slate-500">None</li>
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
                                <span>{{ row.filter_type === 'category_line' ? 'Category line' : 'Series' }}</span>
                                <span v-if="manufacturerFilterBadge(row)" class="ml-2">Preorder {{ manufacturerFilterBadge(row) }}</span>
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
                        <li v-if="manufacturerFilters.include.length === 0" class="text-slate-500">None</li>
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
                                <span>{{ row.filter_type === 'category_line' ? 'Category line' : 'Series' }}</span>
                                <span v-if="manufacturerFilterBadge(row)" class="ml-2">Preorder {{ manufacturerFilterBadge(row) }}</span>
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
                        <li v-if="manufacturerFilters.exclude.length === 0" class="text-slate-500">None</li>
                    </ul>
                </div>
            </div>
        </div>

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
                <h3 class="text-xs font-semibold uppercase tracking-wide text-red-700">Not found</h3>
                <ul class="mt-1 space-y-1 text-sm text-red-700">
                    <li v-for="nf in pasteResult.not_found" :key="nf">{{ nf }}</li>
                </ul>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label for="preorders-search" class="text-xs font-medium text-slate-600">Search</label>
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
            <label class="flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm">
                <input v-model="newOnly" type="checkbox" class="rounded border-slate-300" />
                New only
            </label>
        </div>

        <p v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</p>
        <p v-if="pasteGridActive" class="text-sm text-slate-600">
            Showing multi-line search results ({{ pasteGridRows.length }} rows).
            <button
                type="button"
                class="ml-1 text-blue-700 underline"
                @click="clearPasteGrid(); fetchRows()"
            >
                Clear and show all preorders
            </button>
        </p>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-3 py-2">Image</th>
                        <th class="px-3 py-2">Product</th>
                        <th class="px-3 py-2">Release</th>
                        <th class="px-3 py-2">Category</th>
                        <th class="px-3 py-2">Stock $</th>
                        <th class="px-3 py-2">Sell $</th>
                        <th class="px-3 py-2">PO qty</th>
                        <th class="px-3 py-2">PO due</th>
                        <th class="px-3 py-2">ETA</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in displayRows"
                        :key="row.sku"
                        class="border-b border-slate-100 hover:bg-slate-50"
                    >
                        <td class="px-3 py-2 align-middle">
                            <img
                                v-if="row.image_url"
                                :src="row.image_url"
                                :alt="row.product_name"
                                class="h-20 w-20 rounded border border-slate-200 object-contain bg-white"
                                loading="lazy"
                            />
                            <span v-else class="inline-flex h-20 w-20 items-center justify-center rounded border border-dashed border-slate-200 text-[10px] text-slate-400">
                                {{ row.image_download_status }}
                            </span>
                        </td>
                        <td class="max-w-[28rem] px-3 py-2 align-middle">
                            <div class="font-medium text-slate-900">{{ row.product_name }}</div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
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
                                    v-if="row.not_in_import"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900"
                                >
                                    Plamod only
                                </span>
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                <span v-if="row.series">{{ row.series }}</span>
                                <span v-if="row.series && row.manufacturer"> · </span>
                                <span v-if="row.manufacturer">{{ row.manufacturer }}</span>
                                <span v-if="!row.series && !row.manufacturer">—</span>
                            </div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.release_date ? formatTorontoDate(row.release_date) : '—' }}
                        </td>
                        <td class="px-3 py-2 align-middle">{{ row.category ?? '—' }}</td>
                        <td class="px-3 py-2 align-middle">{{ formatMoney2OrEmpty(row.price_preorder ?? row.price_stock) }}</td>
                        <td class="px-3 py-2 align-middle font-medium">{{ formatMoney2OrEmpty(row.unit_selling_price) }}</td>
                        <td class="px-3 py-2 align-middle">{{ row.quantity_preorder ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.po_due_date ? formatTorontoDate(row.po_due_date) : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.eta_date ? formatTorontoDate(row.eta_date) : '—' }}
                        </td>
                    </tr>
                    <tr v-if="!loading && displayRows.length === 0">
                        <td colspan="9" class="px-3 py-8 text-center text-slate-500">No preorders found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && !pasteGridActive" class="flex items-center justify-between text-sm text-slate-600">
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
    </div>
</template>

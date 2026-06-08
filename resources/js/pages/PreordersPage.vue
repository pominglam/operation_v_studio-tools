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

type SyncStatus = {
    status: string;
    sync_log_id: number | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    counts: Record<string, number | string>;
    error_summary: string | null;
};

type SearchLinesResult = {
    matched: Array<{ line: string; sku: string; product_name: string; in_snapshot?: boolean }>;
    plamod_only: Array<{ line: string; sku: string; product_name: string; plamod_pdp_url: string }>;
    not_found: string[];
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

const pasteLines = ref('');
const pasteSearching = ref(false);
const pasteSearchStatus = ref<string | null>(null);
const pasteResult = ref<SearchLinesResult | null>(null);
const pasteError = ref<string | null>(null);

const LIVE_SEARCH_BATCH_SIZE = 3;

let pollTimer: number | null = null;

const excludedCategoryOptions = computed<MultiSelectOption[]>(() =>
    categories.value.map((c) => ({ value: c, label: c })),
);

const syncBusy = computed(
    () => syncing.value || ['queued', 'running'].includes(syncStatus.value?.status ?? ''),
);

const syncPhaseLabel = computed(() => {
    const counts = syncStatus.value?.counts ?? {};
    const phase = String(counts.phase ?? '');
    if (phase === 'images') {
        const total = Number(counts.images_total ?? 0);
        const done = Number(counts.images_completed ?? 0) + Number(counts.images_failed ?? 0);
        return `Downloading images (${done}/${total})`;
    }
    if (syncBusy.value) return 'Importing CSV…';
    return '';
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

async function fetchRows(opts?: { silent?: boolean }): Promise<void> {
    if (!opts?.silent) {
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
            data: { matched: SearchLinesResult['matched']; pending_live: string[] };
        }>('/api/v1/preorders/search-lines', { lines, phase: 'snapshot' }, { timeout: 60_000 });

        const matched = snapshotRes.data.data?.matched ?? [];
        const pendingLive = snapshotRes.data.data?.pending_live ?? [];
        pasteResult.value = { matched, plamod_only: [], not_found: [] };

        if (pendingLive.length === 0) {
            pasteSearchStatus.value = 'Done — all lines matched the imported snapshot.';
            return;
        }

        const totalBatches = Math.ceil(pendingLive.length / LIVE_SEARCH_BATCH_SIZE);
        const plamodOnly: SearchLinesResult['plamod_only'] = [];
        const notFound: string[] = [];

        for (let i = 0; i < pendingLive.length; i += LIVE_SEARCH_BATCH_SIZE) {
            const batch = pendingLive.slice(i, i + LIVE_SEARCH_BATCH_SIZE);
            const batchNo = Math.floor(i / LIVE_SEARCH_BATCH_SIZE) + 1;
            pasteSearchStatus.value = `Step 2/2: Searching Plamod live (batch ${batchNo}/${totalBatches}, ${batch.length} line${batch.length === 1 ? '' : 's'})…`;

            const liveRes = await api.post<{
                data: Pick<SearchLinesResult, 'plamod_only' | 'not_found'>;
            }>(
                '/api/v1/preorders/search-lines',
                { lines: batch, phase: 'live' },
                { timeout: 180_000 },
            );

            plamodOnly.push(...(liveRes.data.data?.plamod_only ?? []));
            notFound.push(...(liveRes.data.data?.not_found ?? []));
            pasteResult.value = { matched, plamod_only: [...plamodOnly], not_found: [...notFound] };
        }

        pasteSearchStatus.value = `Done — ${matched.length} imported, ${plamodOnly.length} on Plamod only, ${notFound.length} not found.`;
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
    await Promise.all([fetchRows(), loadSettings(), loadSyncStatus()]);
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
                    <span v-if="syncPhaseLabel" class="ml-2 font-medium text-amber-700">
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

        <p v-if="syncError" class="text-sm text-red-700">{{ syncError }}</p>
        <p v-if="syncStatus?.status === 'failed' && syncStatus.error_summary" class="text-sm text-red-700">
            Sync failed: {{ syncStatus.error_summary }}
        </p>

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
            <div v-if="pasteResult" class="mt-3 space-y-3">
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Matched (imported)</h3>
                        <ul class="mt-1 space-y-1 text-sm">
                            <li v-for="m in pasteResult.matched" :key="m.line">
                                <span class="font-mono text-slate-700">{{ m.sku }}</span>
                                — {{ m.product_name }}
                                <span class="text-slate-400">({{ m.line }})</span>
                            </li>
                            <li v-if="pasteResult.matched.length === 0" class="text-slate-500">None</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Not found</h3>
                        <ul class="mt-1 space-y-1 text-sm text-red-700">
                            <li v-for="nf in pasteResult.not_found" :key="nf">{{ nf }}</li>
                            <li v-if="pasteResult.not_found.length === 0" class="text-slate-500">None</li>
                        </ul>
                    </div>
                </div>
                <div v-if="pasteResult.plamod_only.length > 0">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-700">
                        On Plamod (not in latest import)
                    </h3>
                    <ul class="mt-1 space-y-1 text-sm text-amber-900">
                        <li v-for="p in pasteResult.plamod_only" :key="p.line">
                            <a
                                :href="p.plamod_pdp_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-mono underline"
                            >{{ p.sku }}</a>
                            — {{ p.product_name }}
                            <span class="text-amber-700/80">({{ p.line }})</span>
                        </li>
                    </ul>
                </div>
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
                        v-for="row in rows"
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
                        <td class="px-3 py-2 align-middle">{{ formatMoney2OrEmpty(row.price_stock) }}</td>
                        <td class="px-3 py-2 align-middle font-medium">{{ formatMoney2OrEmpty(row.unit_selling_price) }}</td>
                        <td class="px-3 py-2 align-middle">{{ row.quantity_preorder ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.po_due_date ? formatTorontoDate(row.po_due_date) : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap align-middle">
                            {{ row.eta_date ? formatTorontoDate(row.eta_date) : '—' }}
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="9" class="px-3 py-8 text-center text-slate-500">No preorders found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta" class="flex items-center justify-between text-sm text-slate-600">
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

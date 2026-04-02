<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../lib/api';
import { formatTorontoDateTime, formatTorontoEpochSeconds } from '../lib/datetime';
import { navigateTo } from '../lib/navigation';
import DebugLogDialog from '../components/jobs/DebugLogDialog.vue';

type JobBatchListItem = {
    id: string;
    name: string;
    total_jobs: number;
    pending_jobs: number;
    failed_jobs: number;
    created_at: number;
    finished_at: number | null;
    cancelled_at: number | null;
};

type JobBatchStatus = {
    id: string;
    name: string;
    total_jobs: number;
    pending_jobs: number;
    processed_jobs: number;
    failed_jobs: number;
    progress_percent: number;
    cancelled: boolean;
    finished_at: string | null;
    cancelled_at: string | null;
};

type JobBatchItem = {
    product_uuid: string;
    sku: string | null;
    product_name?: string | null;
    vendor: string | null;
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'skipped';
    attempts: number;
    sync_uuid: string | null;
    last_error: string | null;
    debug_log?: string | null;
    started_at: string | null;
    finished_at: string | null;
};

type JobBatchItemsSummary = {
    counts: {
        queued: number;
        running: number;
        succeeded: number;
        failed: number;
        skipped: number;
    };
    running: JobBatchItem[];
    queued: JobBatchItem[];
    done: JobBatchItem[];
};

const route = useRoute();
const router = useRouter();

const listLoading = ref(false);
const listError = ref<string | null>(null);
const batches = ref<JobBatchListItem[]>([]);

const selectedId = ref<string | null>(null);
const manualId = ref('');

const statusLoading = ref(false);
const statusError = ref<string | null>(null);
const status = ref<JobBatchStatus | null>(null);
const itemsLoading = ref(false);
const itemsError = ref<string | null>(null);
const items = ref<JobBatchItemsSummary | null>(null);
const cancelBusy = ref(false);
const cancelMessage = ref<string | null>(null);
const resumeBusy = ref(false);
const resumeMessage = ref<string | null>(null);
const resumeError = ref<string | null>(null);

let pollTimer: number | null = null;

const debugDialogOpen = ref(false);
const debugDialogTitle = ref<string>('');
const debugDialogSubtitle = ref<string | null>(null);
const debugDialogLog = ref<string>('');

const autoExportBusy = ref(false);
const autoExportMessage = ref<string | null>(null);
const autoExportError = ref<string | null>(null);
const autoExportTriggered = ref(false);

function openDebugDialog(it: JobBatchItem): void {
    const sku = it.sku ?? it.product_uuid;
    debugDialogTitle.value = `Debug log · ${sku}`;
    const bits: string[] = [];
    if (it.product_name) bits.push(it.product_name);
    if (it.vendor) bits.push(it.vendor);
    bits.push(it.status);
    if (it.sync_uuid) bits.push(`sync ${it.sync_uuid.slice(0, 8)}`);
    debugDialogSubtitle.value = bits.join(' · ');
    debugDialogLog.value = it.debug_log ?? '';
    debugDialogOpen.value = true;
}

function closeDebugDialog(): void {
    debugDialogOpen.value = false;
}

function hasDebugLog(it: JobBatchItem): boolean {
    return typeof it.debug_log === 'string' && it.debug_log.trim() !== '';
}

function stopPolling(): void {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function loadList(): Promise<void> {
    listLoading.value = true;
    listError.value = null;
    try {
        const res = await api.get<{ ok: boolean; data: JobBatchListItem[] }>('/api/v1/job-batches', {
            params: { limit: 50 },
        });
        batches.value = res.data.data ?? [];
    } catch {
        listError.value = 'Failed to load job batches.';
    } finally {
        listLoading.value = false;
    }
}

async function loadStatus(id: string): Promise<void> {
    statusLoading.value = true;
    statusError.value = null;
    cancelMessage.value = null;
    resumeMessage.value = null;
    resumeError.value = null;
    try {
        const res = await api.get<{ ok: boolean; data: JobBatchStatus }>(`/api/v1/job-batches/${id}`);
        status.value = res.data.data;
    } catch {
        status.value = null;
        statusError.value = 'Failed to load batch status (not found or expired).';
    } finally {
        statusLoading.value = false;
    }
}

async function loadItems(id: string): Promise<void> {
    itemsLoading.value = true;
    itemsError.value = null;
    try {
        const res = await api.get<{ ok: boolean; data: JobBatchItemsSummary }>(`/api/v1/job-batches/${id}/items`, {
            params: { limit: 25 },
        });
        items.value = res.data.data;
    } catch {
        items.value = null;
        itemsError.value = 'Failed to load batch item details.';
    } finally {
        itemsLoading.value = false;
    }
}

async function cancelBatch(): Promise<void> {
    if (!selectedId.value) return;
    cancelBusy.value = true;
    cancelMessage.value = null;
    statusError.value = null;
    try {
        await api.post(`/api/v1/job-batches/${selectedId.value}/cancel`);
        cancelMessage.value = 'Cancelled. Any in-progress job may finish, but pending jobs will stop.';
        await loadStatus(selectedId.value);
        stopPolling();
    } catch {
        statusError.value = 'Failed to cancel batch.';
    } finally {
        cancelBusy.value = false;
    }
}

const canResume = computed(() => {
    if (!selectedId.value) return false;
    if (!status.value) return false;
    if (status.value.cancelled || status.value.cancelled_at) return false;
    const hasPending = (status.value.pending_jobs ?? 0) > 0;
    const hasFailed = (items.value?.counts?.failed ?? 0) > 0;
    if (!hasPending && !hasFailed) return false;
    if ((items.value?.counts?.running ?? 0) > 0) return false;
    if (status.value.name !== 'recrawl_selected_products') return false;
    return true;
});

async function resumeBatch(): Promise<void> {
    if (!selectedId.value) return;
    resumeBusy.value = true;
    resumeMessage.value = null;
    resumeError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: { resumed: boolean; new_batch_id: string | null; queued: number; reason: string | null };
        }>(`/api/v1/job-batches/${selectedId.value}/resume`);

        const out = res.data.data;
        if (!out.resumed || !out.new_batch_id) {
            resumeMessage.value = out.reason ? `Nothing to resume (${out.reason}).` : 'Nothing to resume.';
            return;
        }

        resumeMessage.value = `Resumed: queued ${out.queued} item(s) in new batch ${out.new_batch_id.slice(0, 8)}…`;
        selectBatch(out.new_batch_id);
    } catch (e: any) {
        const msg = typeof e?.response?.data?.error === 'string' ? e.response.data.error : null;
        resumeError.value = msg ? `Failed to resume: ${msg}` : 'Failed to resume batch.';
    } finally {
        resumeBusy.value = false;
    }
}

function startPolling(): void {
    stopPolling();
    pollTimer = window.setInterval(() => {
        if (!selectedId.value) return;
        void loadStatus(selectedId.value);
        void loadItems(selectedId.value);
    }, 2000);
}

function formatEpochSeconds(s: number): string {
    return formatTorontoEpochSeconds(s);
}

function runningForLabel(startedAt: string | null): string | null {
    if (!startedAt) return null;
    const t0 = Date.parse(startedAt);
    if (!Number.isFinite(t0)) return null;
    const ms = Date.now() - t0;
    if (!Number.isFinite(ms) || ms < 0) return null;
    const totalSec = Math.floor(ms / 1000);
    const m = Math.floor(totalSec / 60);
    const s = totalSec % 60;
    if (m <= 0) return `${s}s`;
    return `${m}m ${s}s`;
}

const progressBarWidth = computed(() => `${status.value?.progress_percent ?? 0}%`);
const isDone = computed(() => !!(status.value?.finished_at || status.value?.cancelled_at || status.value?.cancelled));

function autoExportTypeFromQuery(): string | null {
    const raw = typeof route.query.auto_export === 'string' ? route.query.auto_export.trim() : '';
    return raw !== '' ? raw : null;
}

async function maybeAutoExport(): Promise<void> {
    if (!selectedId.value) return;
    if (!isDone.value) return;
    if (autoExportTriggered.value) return;

    const kind = autoExportTypeFromQuery();
    if (kind !== 'shopify_content') return;

    autoExportTriggered.value = true;
    autoExportBusy.value = true;
    autoExportMessage.value = null;
    autoExportError.value = null;

    const key = `auto_export_shopify_content:${selectedId.value}`;
    let ids: string[] = [];
    try {
        const raw = sessionStorage.getItem(key);
        if (raw) {
            const parsed = JSON.parse(raw) as any;
            const arr = parsed?.ids;
            if (Array.isArray(arr)) {
                ids = arr.map((v) => String(v)).filter((v) => v.trim() !== '');
            }
        }
    } catch {
        // ignore
    }

    if (ids.length === 0) {
        autoExportBusy.value = false;
        autoExportError.value = 'Auto-export could not start (missing selected product ids). Please export manually.';
        return;
    }

    try {
        const res = await api.post<{ download_url: string }>(
            '/api/v1/products/exports/shopify-content/prepare',
            { ids },
            { validateStatus: () => true },
        );

        if (res.status !== 200) {
            const anyData = res.data as any;
            const msgRaw: unknown = anyData?.message ?? anyData?.error ?? anyData?.errors;
            let details = '';
            if (typeof msgRaw === 'string') details = msgRaw.trim();
            else if (msgRaw !== null && msgRaw !== undefined) {
                try {
                    details = JSON.stringify(msgRaw);
                } catch {
                    details = String(msgRaw);
                }
            }
            throw new Error(`Export failed (HTTP ${res.status}).${details ? ` ${details}` : ''}`);
        }

        const downloadUrl = (res.data as any)?.download_url;
        if (typeof downloadUrl !== 'string' || downloadUrl.trim() === '') {
            throw new Error('export_failed');
        }

        try {
            sessionStorage.removeItem(key);
        } catch {
            // ignore
        }

        autoExportMessage.value = 'Export ready. Download starting…';
        navigateTo(downloadUrl);
    } catch (e: any) {
        const msg = typeof e?.message === 'string' ? e.message.trim() : '';
        autoExportError.value = msg !== '' ? msg : 'Auto-export failed. Please export manually.';
    } finally {
        autoExportBusy.value = false;
    }
}

function selectBatch(id: string): void {
    selectedId.value = id;
    void router.replace({ query: { ...route.query, batch_id: id } });
}

function applyManualId(): void {
    const id = manualId.value.trim();
    if (!id) return;
    selectBatch(id);
}

watch(
    () => selectedId.value,
    (id) => {
        stopPolling();
        status.value = null;
        statusError.value = null;
        items.value = null;
        itemsError.value = null;
        resumeMessage.value = null;
        resumeError.value = null;
        autoExportBusy.value = false;
        autoExportMessage.value = null;
        autoExportError.value = null;
        autoExportTriggered.value = false;
        if (!id) return;
        void Promise.all([loadStatus(id), loadItems(id)]).then(() => {
            if (!isDone.value) startPolling();
            void maybeAutoExport();
        });
    },
);

watch(
    () => isDone.value,
    (done) => {
        if (!done) return;
        void maybeAutoExport();
    },
);

onMounted(() => {
    void loadList();
    const fromQuery = typeof route.query.batch_id === 'string' ? route.query.batch_id : null;
    if (fromQuery) {
        selectedId.value = fromQuery;
        manualId.value = fromQuery;
    } else {
        // Default to the most recent batch if present.
        const first = batches.value[0]?.id ?? null;
        if (first) selectedId.value = first;
    }
});

onUnmounted(() => stopPolling());
</script>

<template>
    <section class="mx-auto max-w-7xl p-4">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <div class="text-lg font-semibold text-slate-900">Sync Progress</div>
                <div class="text-sm text-slate-600">
                    Shows progress for tracked runs (job batches). Older runs started before batching won't show a true
                    percent—start a new sync to track it.
                </div>
            </div>
            <button
                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                type="button"
                :disabled="listLoading"
                @click="loadList"
            >
                {{ listLoading ? 'Refreshing…' : 'Refresh list' }}
            </button>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="mb-2 text-sm font-semibold text-slate-900">Find a batch</div>
                <div class="flex gap-2">
                    <input
                        v-model="manualId"
                        class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
                        type="text"
                        placeholder="Paste batch id…"
                        @keyup.enter="applyManualId"
                    />
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-50"
                        type="button"
                        @click="applyManualId"
                    >
                        View
                    </button>
                </div>

                <div v-if="listError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ listError }}
                </div>

                <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-600">Recent batches</div>
                <div class="mt-2 max-h-[420px] divide-y divide-slate-100 overflow-auto">
                    <button
                        v-for="b in batches"
                        :key="b.id"
                        class="flex w-full items-start justify-between gap-3 px-2 py-2 text-left hover:bg-slate-50"
                        type="button"
                        @click="selectBatch(b.id)"
                    >
                        <div>
                            <div class="text-sm font-medium text-slate-900">
                                {{ b.name }}
                            </div>
                            <div class="text-xs text-slate-600">
                                {{ formatEpochSeconds(b.created_at) }}
                            </div>
                            <div class="mt-1 text-xs text-slate-600">
                                {{ b.total_jobs - b.pending_jobs }}/{{ b.total_jobs }}
                                <span v-if="b.failed_jobs > 0" class="text-rose-700"> · failed {{ b.failed_jobs }}</span>
                            </div>
                        </div>
                        <div class="text-xs text-slate-500">
                            <span v-if="selectedId === b.id" class="font-semibold text-slate-900">Viewing</span>
                        </div>
                    </button>
                    <div v-if="!listLoading && batches.length === 0" class="px-2 py-3 text-sm text-slate-600">
                        No batches found yet.
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 lg:col-span-2">
                <div class="mb-2 flex items-center justify-between">
                    <div class="text-sm font-semibold text-slate-900">Progress</div>
                    <div class="text-xs text-slate-600">
                        <span v-if="selectedId">Batch: {{ selectedId }}</span>
                    </div>
                </div>

                <div v-if="statusError" class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ statusError }}
                </div>
                <div v-if="cancelMessage" class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ cancelMessage }}
                </div>
                <div v-if="resumeMessage" class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ resumeMessage }}
                </div>
                <div v-if="resumeError" class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ resumeError }}
                </div>
                <div
                    v-if="autoExportError"
                    class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                >
                    {{ autoExportError }}
                </div>
                <div
                    v-if="autoExportMessage"
                    class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    {{ autoExportMessage }}
                </div>

                <div v-if="status" class="space-y-3">
                    <div class="flex items-center justify-between text-sm text-slate-700">
                        <div>
                            <span class="font-semibold text-slate-900">{{ status.name }}</span>
                        </div>
                        <div class="tabular-nums">
                            {{ status.progress_percent }}% ({{ status.processed_jobs }}/{{ status.total_jobs }})
                            <span v-if="status.failed_jobs > 0" class="text-rose-700"> · failed {{ status.failed_jobs }}</span>
                        </div>
                    </div>

                    <div class="w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-3 bg-slate-900" :style="{ width: progressBarWidth }" />
                    </div>

                    <div class="text-xs text-slate-600">
                        Pending: <span class="font-medium text-slate-900">{{ status.pending_jobs }}</span>
                        · Processed: <span class="font-medium text-slate-900">{{ status.processed_jobs }}</span>
                        · Total: <span class="font-medium text-slate-900">{{ status.total_jobs }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="text-xs text-slate-600">
                            <span v-if="status.cancelled || status.cancelled_at" class="text-rose-700 font-semibold">Cancelled</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="canResume"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                                type="button"
                                :disabled="resumeBusy"
                                @click="resumeBatch"
                            >
                                {{ resumeBusy ? 'Resuming…' : 'Resume batch' }}
                            </button>
                            <button
                                v-if="!isDone"
                                class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 transition hover:bg-rose-50 disabled:opacity-50"
                                type="button"
                                :disabled="cancelBusy"
                                @click="cancelBatch"
                            >
                                {{ cancelBusy ? 'Cancelling…' : 'Cancel batch' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="status.finished_at" class="text-xs text-emerald-700">
                        Finished at: <span class="font-medium">{{ formatTorontoDateTime(status.finished_at) }}</span>
                    </div>
                    <div v-else-if="status.cancelled_at" class="text-xs text-rose-700">
                        Cancelled at: <span class="font-medium">{{ formatTorontoDateTime(status.cancelled_at) }}</span>
                    </div>
                    <div v-else class="text-xs text-slate-600">
                        Running… (auto-refreshing)
                    </div>

                    <div class="mt-4 border-t border-slate-100 pt-4">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-semibold text-slate-900">Details</div>
                        <div v-if="items" class="text-xs text-slate-600 tabular-nums">
                            Queued: <span class="font-semibold text-slate-900">{{ items.counts.queued }}</span>
                            · Running: <span class="font-semibold text-slate-900">{{ items.counts.running }}</span>
                            · Done: <span class="font-semibold text-slate-900">{{ items.counts.succeeded + items.counts.failed + items.counts.skipped }}</span>
                            <span v-if="items.counts.failed > 0" class="text-rose-700"> · failed {{ items.counts.failed }}</span>
                        </div>
                    </div>

                    <div v-if="itemsError" class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                        {{ itemsError }}
                    </div>

                    <div v-else-if="itemsLoading && !items" class="text-sm text-slate-600">Loading item details…</div>

                    <div v-else-if="items" class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                        <div class="rounded-md border border-slate-200 bg-white p-3">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Queued</div>
                            <div v-if="items.queued.length === 0" class="text-sm text-slate-600">—</div>
                            <ul v-else class="space-y-2 text-sm">
                                <li v-for="it in items.queued" :key="it.product_uuid" class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-slate-900">{{ it.sku ?? it.product_uuid }}</div>
                                        <div v-if="it.product_name" class="truncate text-xs text-slate-700">
                                            {{ it.product_name }}
                                        </div>
                                        <div class="text-xs text-slate-600">
                                            <span v-if="it.vendor">{{ it.vendor }}</span>
                                            <span v-else>—</span>
                                        </div>
                                    </div>
                                    <div class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">queued</div>
                                </li>
                            </ul>
                        </div>

                        <div class="rounded-md border border-slate-200 bg-white p-3">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Processing</div>
                            <div v-if="items.running.length === 0" class="text-sm text-slate-600">—</div>
                            <ul v-else class="space-y-2 text-sm">
                                <li v-for="it in items.running" :key="it.product_uuid" class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-slate-900">{{ it.sku ?? it.product_uuid }}</div>
                                        <div v-if="it.product_name" class="truncate text-xs text-slate-700">
                                            {{ it.product_name }}
                                        </div>
                                        <div class="text-xs text-slate-600">
                                            <span v-if="it.vendor">{{ it.vendor }} · </span>
                                            attempts {{ it.attempts }}
                                            <span v-if="it.started_at"> · started {{ formatTorontoDateTime(it.started_at) }}</span>
                                            <span v-if="it.started_at"> · running for {{ runningForLabel(it.started_at) }}</span>
                                        </div>
                                        <div v-if="hasDebugLog(it)" class="mt-1">
                                            <button
                                                type="button"
                                                class="text-xs font-semibold text-slate-700 underline underline-offset-2 hover:text-slate-900"
                                                @click="openDebugDialog(it)"
                                            >
                                                View details
                                            </button>
                                        </div>
                                    </div>
                                    <div class="shrink-0 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800">running</div>
                                </li>
                            </ul>
                        </div>

                        <div class="rounded-md border border-slate-200 bg-white p-3">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Processed (recent)</div>
                            <div v-if="items.done.length === 0" class="text-sm text-slate-600">—</div>
                            <ul v-else class="space-y-2 text-sm">
                                <li v-for="it in items.done" :key="it.product_uuid" class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-slate-900">{{ it.sku ?? it.product_uuid }}</div>
                                        <div v-if="it.product_name" class="truncate text-xs text-slate-700">
                                            {{ it.product_name }}
                                        </div>
                                        <div class="text-xs text-slate-600">
                                            <span v-if="it.finished_at">finished {{ formatTorontoDateTime(it.finished_at) }}</span>
                                            <span v-if="it.last_error && it.status !== 'skipped'"> · <span class="text-rose-700">{{ it.last_error }}</span></span>
                                        </div>
                                        <div v-if="hasDebugLog(it)" class="mt-1">
                                            <button
                                                type="button"
                                                class="text-xs font-semibold text-slate-700 underline underline-offset-2 hover:text-slate-900"
                                                @click="openDebugDialog(it)"
                                            >
                                                View details
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="
                                            it.status === 'succeeded'
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : it.status === 'failed'
                                                  ? 'bg-rose-100 text-rose-800'
                                                  : it.status === 'skipped'
                                                    ? 'bg-amber-100 text-amber-800'
                                                    : 'bg-slate-100 text-slate-700'
                                        "
                                    >
                                        {{ it.status === 'skipped' && it.last_error === 'no_sources_found' ? 'not found' : it.status }}
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                </div>

                <div v-else class="text-sm text-slate-600">
                    <span v-if="statusLoading">Loading…</span>
                    <span v-else>Select a batch from the list, or paste a batch id.</span>
                </div>
            </div>
        </div>
    </section>

    <DebugLogDialog
        :open="debugDialogOpen"
        :title="debugDialogTitle"
        :subtitle="debugDialogSubtitle"
        :debug-log="debugDialogLog"
        :on-close="closeDebugDialog"
    />
</template>



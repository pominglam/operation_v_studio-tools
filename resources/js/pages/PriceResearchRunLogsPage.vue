<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { formatLocalDateTime } from '../lib/datetime';

type RunStatus = {
    id: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    processed_products: number;
    total_products: number;
    processed_sites: number;
    total_sites: number;
    quotes_written: number;
    started_at: string | null;
    finished_at: string | null;
    error_message: string | null;
};

type RunLog = {
    id: number;
    run_id: string;
    product_id: string;
    sku: string;
    description: string | null;
    site_key: string;
    site_name: string;
    status: string;
    product_url: string | null;
    error_message: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    created_at: string | null;
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

const route = useRoute();
const runId = computed<string>(() => String(route.params.id ?? ''));

const loading = ref(false);
const error = ref<string | null>(null);
const run = ref<RunStatus | null>(null);
const logs = ref<RunLog[]>([]);
const meta = ref<Paginated<RunLog>['meta'] | null>(null);

const perPage = ref(50);
const page = ref(1);
const destroyed = ref(false);
const polling = ref(false);

const total = computed<number>(() => meta.value?.total ?? 0);
const currentPage = computed<number>(() => meta.value?.current_page ?? page.value);
const lastPage = computed<number>(() => meta.value?.last_page ?? 1);

function statusPillClass(status: string): string {
    if (status === 'found') return 'bg-emerald-100 text-emerald-800';
    if (status === 'not_found') return 'bg-slate-100 text-slate-700';
    if (status === 'running') return 'bg-sky-100 text-sky-800';
    return 'bg-rose-100 text-rose-800';
}

async function loadRun(): Promise<void> {
    try {
        const r = await fetch(`/api/v1/price-research/runs/${runId.value}`);
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const json = (await r.json()) as { data: RunStatus };
        run.value = json.data;
    } catch {
        // ignore
    }
}

async function loadLogs(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const params = new URLSearchParams();
        params.set('per_page', String(perPage.value));
        params.set('page', String(page.value));
        const r = await fetch(
            `/api/v1/price-research/runs/${runId.value}/logs?${params.toString()}`,
        );
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const json = (await r.json()) as Paginated<RunLog>;
        logs.value = json.data;
        meta.value = json.meta;
    } catch {
        error.value = 'Failed to load crawl logs.';
    } finally {
        loading.value = false;
    }
}

async function pollWhileRunning(): Promise<void> {
    if (polling.value) return;
    polling.value = true;
    try {
        while (!destroyed.value) {
            await loadRun();
            await loadLogs();
            if (run.value?.status !== 'queued' && run.value?.status !== 'running') break;
            await new Promise((resolve) => window.setTimeout(resolve, 1500));
        }
    } finally {
        polling.value = false;
    }
}

watch([perPage], () => {
    page.value = 1;
});
watch([page, perPage, runId], () => {
    void loadLogs();
});

onMounted(async () => {
    await loadRun();
    await loadLogs();
    if (run.value?.status === 'queued' || run.value?.status === 'running') {
        void pollWhileRunning();
    }
});

onBeforeUnmount(() => {
    destroyed.value = true;
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Crawl logs</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Run:
                    <span class="font-mono text-xs text-slate-700">{{ runId }}</span>
                </p>
            </div>
            <RouterLink
                to="/price-research"
                class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
            >
                Back to research
            </RouterLink>
        </div>

        <div v-if="run" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="font-semibold text-slate-900">Run status</div>
                    <div class="mt-1 text-slate-700">
                        Status: <span class="font-medium">{{ run.status }}</span>
                        <span v-if="run.started_at">
                            • Started: {{ formatLocalDateTime(run.started_at) }}</span
                        >
                        <span v-if="run.finished_at">
                            • Finished: {{ formatLocalDateTime(run.finished_at) }}</span
                        >
                    </div>
                    <div v-if="run.error_message" class="mt-1 text-rose-700">
                        {{ run.error_message }}
                    </div>
                </div>
                <div class="text-right text-slate-700">
                    <div class="font-medium">
                        {{ run.processed_products }} / {{ run.total_products }} products
                    </div>
                    <div>
                        {{ run.processed_sites }} / {{ run.total_sites * run.total_products }} site
                        checks
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3">
            <div class="text-sm text-slate-700">Showing {{ logs.length }} of {{ total }}</div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-slate-700">Per page</label>
                <select
                    v-model.number="perPage"
                    class="rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                >
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                    <option :value="200">200</option>
                </select>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr
                            class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >
                            <th class="px-4 py-3">Time</th>
                            <th class="px-4 py-3">SKU</th>
                            <th class="px-4 py-3">Site</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Duration</th>
                            <th class="px-4 py-3">URL</th>
                            <th class="px-4 py-3">Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="logs.length === 0">
                            <td class="px-4 py-4 text-slate-600" colspan="7">No logs yet.</td>
                        </tr>

                        <tr v-for="l in logs" :key="l.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-700">
                                {{ formatLocalDateTime(l.started_at ?? l.created_at) }}
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-900">
                                <div>{{ l.sku }}</div>
                                <div v-if="l.description" class="mt-0.5 text-xs text-slate-500">
                                    {{ l.description }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ l.site_name }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="statusPillClass(l.status)"
                                >
                                    {{ l.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                                <span v-if="l.duration_ms !== null">{{ l.duration_ms }}ms</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <a
                                    v-if="l.product_url"
                                    class="text-slate-900 underline"
                                    :href="l.product_url"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Open
                                </a>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-rose-700">
                                {{ l.error_message ?? '' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 text-sm">
            <button
                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                type="button"
                :disabled="currentPage <= 1"
                @click="page = Math.max(1, page - 1)"
            >
                Prev
            </button>
            <div class="text-slate-700">Page {{ currentPage }} / {{ lastPage }}</div>
            <button
                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                type="button"
                :disabled="currentPage >= lastPage"
                @click="page = Math.min(lastPage, page + 1)"
            >
                Next
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api } from '../lib/api';
import { formatLocalDateTime } from '../lib/datetime';
import PaginationControls from '../components/ui/PaginationControls.vue';

type QuoteReport = {
    id: number;
    created_at: string | null;
    handled_at: string | null;
    run_id: string | null;
    product_id: string;
    sku: string;
    description: string | null;
    site_key: string;
    site_name: string;
    status: string | null;
    availability: string | null;
    currency: string | null;
    price: string | null;
    original_price: string | null;
    product_url: string | null;
    error_message: string | null;
    fetched_at: string | null;
    note: string | null;
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
const error = ref<string | null>(null);
const reports = ref<QuoteReport[]>([]);
const meta = ref<Paginated<QuoteReport>['meta'] | null>(null);

const perPage = ref(50);
const page = ref(1);
const handlingId = ref<number | null>(null);

const total = computed<number>(() => meta.value?.total ?? 0);
const currentPage = computed<number>(() => meta.value?.current_page ?? page.value);
const lastPage = computed<number>(() => meta.value?.last_page ?? 1);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const res = await api.get<Paginated<QuoteReport>>('/api/v1/price-research/reports', {
            params: { per_page: perPage.value, page: page.value },
        });
        reports.value = res.data.data;
        meta.value = res.data.meta;
    } catch {
        error.value = 'Failed to load reports.';
    } finally {
        loading.value = false;
    }
}

async function markHandled(id: number): Promise<void> {
    handlingId.value = id;
    error.value = null;

    try {
        const res = await api.patch<{ data: QuoteReport }>(
            `/api/v1/price-research/reports/${id}/handled`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            error.value = 'Failed to mark handled.';
            return;
        }

        const idx = reports.value.findIndex((r) => r.id === id);
        if (idx >= 0) {
            reports.value[idx] = res.data.data;
        }
    } catch {
        error.value = 'Failed to mark handled.';
    } finally {
        handlingId.value = null;
    }
}

function onPageChange(next: number): void {
    page.value = Math.max(1, next);
}

watch([perPage], () => {
    page.value = 1;
});
watch([page, perPage], () => void load());

onMounted(() => void load());
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Reported quotes</h1>
                <p class="mt-1 text-sm text-slate-600">Notes you left on quotes that look wrong.</p>
            </div>

            <RouterLink
                to="/price-research"
                class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
            >
                Back to research
            </RouterLink>
        </div>

        <div class="flex items-center justify-between gap-3">
            <div class="text-sm text-slate-700">Showing {{ reports.length }} of {{ total }}</div>
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
                            <th class="px-4 py-3 text-right">Price</th>
                            <th class="px-4 py-3">URL</th>
                            <th class="px-4 py-3">Note</th>
                            <th class="px-4 py-3 text-right">Handled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="reports.length === 0">
                            <td class="px-4 py-4 text-slate-600" colspan="7">No reports yet.</td>
                        </tr>

                        <tr v-for="r in reports" :key="r.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-700">
                                {{ formatLocalDateTime(r.created_at) }}
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-900">
                                <div>{{ r.sku }}</div>
                                <div v-if="r.description" class="mt-0.5 text-xs text-slate-500">
                                    {{ r.description }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                <div>{{ r.site_name }}</div>
                                <div v-if="r.status" class="mt-0.5 text-xs text-slate-500">
                                    {{ r.status
                                    }}<span v-if="r.availability"> • {{ r.availability }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                {{ r.price ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <a
                                    v-if="r.product_url"
                                    class="text-slate-900 underline"
                                    :href="r.product_url"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Open
                                </a>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                <span v-if="r.note">{{ r.note }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div v-if="r.handled_at" class="text-xs text-slate-500">
                                    {{ formatLocalDateTime(r.handled_at) }}
                                </div>
                                <button
                                    v-else
                                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                                    type="button"
                                    :disabled="handlingId === r.id"
                                    @click="markHandled(r.id)"
                                >
                                    {{ handlingId === r.id ? 'Saving…' : 'Mark handled' }}
                                </button>
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
    </section>
</template>

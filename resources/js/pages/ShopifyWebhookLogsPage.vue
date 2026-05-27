<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api } from '../lib/api';
import { formatLocalDateTime } from '../lib/datetime';

type WebhookLogRow = {
    id: number;
    shop_domain: string;
    topic: string;
    shopify_webhook_id: string | null;
    request_id: string | null;
    verification_ok: boolean;
    processing_status: string;
    verification_error: string | null;
    processing_error: string | null;
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

const loading = ref(false);
const error = ref<string | null>(null);
const logs = ref<WebhookLogRow[]>([]);
const meta = ref<Paginated<WebhookLogRow>['meta'] | null>(null);
const page = ref(1);
const topicFilter = ref('');
const verificationFilter = ref<'all' | 'ok' | 'failed'>('all');
const expandedId = ref<number | null>(null);
const expandedPayload = ref<unknown>(null);
const payloadLoading = ref(false);

const total = computed(() => meta.value?.total ?? 0);
const lastPage = computed(() => meta.value?.last_page ?? 1);

function statusClass(ok: boolean): string {
    return ok ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800';
}

function processingClass(status: string): string {
    if (status === 'processed') return 'bg-emerald-100 text-emerald-800';
    if (status === 'failed') return 'bg-rose-100 text-rose-800';
    if (status === 'dispatched') return 'bg-sky-100 text-sky-800';
    return 'bg-slate-100 text-slate-700';
}

async function loadLogs(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const params = new URLSearchParams();
        params.set('page', String(page.value));
        params.set('per_page', '50');
        if (topicFilter.value.trim() !== '') params.set('topic', topicFilter.value.trim());
        if (verificationFilter.value === 'ok') params.set('verification_ok', 'true');
        if (verificationFilter.value === 'failed') params.set('verification_ok', 'false');

        const res = await api.get<Paginated<WebhookLogRow>>(`/api/v1/shopify/webhook-logs?${params}`);
        logs.value = res.data.data;
        meta.value = res.data.meta;
    } catch {
        error.value = 'Failed to load webhook logs.';
    } finally {
        loading.value = false;
    }
}

async function togglePayload(id: number): Promise<void> {
    if (expandedId.value === id) {
        expandedId.value = null;
        expandedPayload.value = null;
        return;
    }
    expandedId.value = id;
    expandedPayload.value = null;
    payloadLoading.value = true;
    try {
        const res = await api.get<{ data: WebhookLogRow & { payload_json: unknown } }>(
            `/api/v1/shopify/webhook-logs/${id}`,
        );
        expandedPayload.value = res.data.data.payload_json;
    } catch {
        expandedPayload.value = { error: 'Failed to load payload.' };
    } finally {
        payloadLoading.value = false;
    }
}

onMounted(() => {
    void loadLogs();
});

watch([page, topicFilter, verificationFilter], () => {
    void loadLogs();
});
</script>

<template>
    <div class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Shopify webhook logs</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Incoming webhook deliveries (HMAC verification + processing status).
                </p>
            </div>
            <RouterLink
                to="/maintenance"
                class="text-sm text-slate-700 underline hover:text-slate-900"
            >
                ← Maintenance
            </RouterLink>
        </div>

        <div class="mb-4 flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-4">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Topic</span>
                <input
                    v-model="topicFilter"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    placeholder="orders/create"
                />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Verified</span>
                <select v-model="verificationFilter" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="all">All</option>
                    <option value="ok">OK</option>
                    <option value="failed">Failed</option>
                </select>
            </label>
            <div class="flex items-end text-sm text-slate-600">{{ total }} rows</div>
        </div>

        <div
            v-if="error"
            class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-left text-slate-700">
                    <tr>
                        <th class="px-3 py-2">Received</th>
                        <th class="px-3 py-2">Topic</th>
                        <th class="px-3 py-2">Verified</th>
                        <th class="px-3 py-2">Processing</th>
                        <th class="px-3 py-2">Shop</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading">
                        <td colspan="6" class="px-3 py-4 text-slate-600">Loading…</td>
                    </tr>
                    <tr v-for="log in logs" :key="log.id">
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ formatLocalDateTime(log.created_at) }}
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ log.topic }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs" :class="statusClass(log.verification_ok)">
                                {{ log.verification_ok ? 'OK' : 'Fail' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded px-2 py-0.5 text-xs"
                                :class="processingClass(log.processing_status)"
                            >
                                {{ log.processing_status }}
                            </span>
                            <div v-if="log.processing_error" class="mt-1 text-xs text-rose-700">
                                {{ log.processing_error }}
                            </div>
                            <div v-if="log.verification_error" class="mt-1 text-xs text-rose-700">
                                {{ log.verification_error }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ log.shop_domain }}</td>
                        <td class="px-3 py-2 text-right">
                            <button
                                type="button"
                                class="text-slate-700 underline"
                                @click="togglePayload(log.id)"
                            >
                                {{ expandedId === log.id ? 'Hide' : 'Payload' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <pre
            v-if="expandedId !== null"
            class="mt-4 max-h-96 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs"
        >{{ payloadLoading ? 'Loading payload…' : JSON.stringify(expandedPayload, null, 2) }}</pre>

        <div class="mt-4 flex items-center justify-between text-sm">
            <button
                type="button"
                class="rounded-md border border-slate-200 px-3 py-1 disabled:opacity-50"
                :disabled="page <= 1 || loading"
                @click="page = Math.max(1, page - 1)"
            >
                Previous
            </button>
            <span>Page {{ page }} / {{ lastPage }}</span>
            <button
                type="button"
                class="rounded-md border border-slate-200 px-3 py-1 disabled:opacity-50"
                :disabled="page >= lastPage || loading"
                @click="page = page + 1"
            >
                Next
            </button>
        </div>
    </div>
</template>

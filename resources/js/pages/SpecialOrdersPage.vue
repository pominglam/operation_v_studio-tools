<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import { RouterLink } from 'vue-router';

import SpecialOrderListStatusSelect from '../components/specialOrders/SpecialOrderListStatusSelect.vue';
import SpecialOrderWorkflowTimelineFilter from '../components/specialOrders/SpecialOrderWorkflowTimelineFilter.vue';

import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';

import { api } from '../lib/api';

import { formatTorontoDate, formatTorontoDateTimeCompact } from '../lib/datetime';

import {
    SPECIAL_ORDER_WORKFLOW_DEFAULT_VISIBLE,
    SPECIAL_ORDER_WORKFLOW_TIMELINE,
    type SpecialOrderWorkflowStatus,
} from '../lib/specialOrderWorkflow';

import { formatMoney2OrEmpty } from '../lib/money';
import {
    specialOrderRemainingBalanceCad,
    specialOrderRemainingBalanceIsPaid,
} from '../lib/specialOrderRemainingBalance';

import { clearPageState, loadPageState, savePageState } from '../lib/pageState';

import { clearSessionState, loadSessionState, saveSessionState } from '../lib/sessionState';

import type {
    SpecialOrder,
    SpecialOrderFilterOptions,
    SpecialOrderWorkflowStatusCounts,
    PaginatedSpecialOrders,
} from '../types/specialOrders';

const PAGE_STATE_KEY = 'special-orders:list:v2';

const WORKFLOW_FILTER_SESSION_KEY = 'special-orders:workflow-filter:v2';

type SortKey =
    | 'created'
    | 'updated'
    | 'contact'
    | 'product_name'
    | 'media'
    | 'product_cost'
    | 'shipping_cost'
    | 'customer_price'
    | 'balance'
    | 'eta';

const search = ref('');

const contactMedia = ref<string[]>([]);

const visibleWorkflowStatuses = ref<SpecialOrderWorkflowStatus[]>([
    ...SPECIAL_ORDER_WORKFLOW_DEFAULT_VISIBLE,
]);

const perPage = ref(50);

const sortBy = ref<SortKey>('created');

const sortDir = ref<'asc' | 'desc'>('desc');

const page = ref(1);

const rows = ref<SpecialOrder[]>([]);

const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });

const filterOptions = ref<SpecialOrderFilterOptions['data'] | null>(null);

const workflowStatusCounts = ref<SpecialOrderWorkflowStatusCounts | null>(null);

const loading = ref(false);

const errorMessage = ref<string | null>(null);

let listBootstrapping = true;

const mediaOptions = computed<MultiSelectOption[]>(() =>
    (filterOptions.value?.contact_media ?? []).map((o) => ({ value: o.value, label: o.label })),
);

function onRowStatusUpdated(updated: SpecialOrder): void {
    const index = rows.value.findIndex((row) => row.id === updated.id);
    if (index >= 0) {
        rows.value[index] = updated;
    }
    void fetchOrders();
}

function onRowStatusError(message: string): void {
    errorMessage.value = message;
}

function toggleSort(key: SortKey): void {
    if (sortBy.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = key;

        sortDir.value =
            key === 'contact' || key === 'media' || key === 'product_name' ? 'asc' : 'desc';
    }

    page.value = 1;
}

function sortIndicator(key: SortKey): string {
    if (sortBy.value !== key) return '';

    return sortDir.value === 'asc' ? '▲' : '▼';
}

function persistWorkflowFilter(): void {
    saveSessionState(WORKFLOW_FILTER_SESSION_KEY, visibleWorkflowStatuses.value);
}

function restoreWorkflowFilter(): void {
    const saved = loadSessionState<SpecialOrderWorkflowStatus[]>(WORKFLOW_FILTER_SESSION_KEY);

    if (!Array.isArray(saved) || saved.length === 0) {
        return;
    }

    const valid = saved.filter((status): status is SpecialOrderWorkflowStatus =>
        SPECIAL_ORDER_WORKFLOW_TIMELINE.includes(status as SpecialOrderWorkflowStatus),
    );

    if (valid.length > 0) {
        visibleWorkflowStatuses.value = valid;
    }
}

async function loadFilterOptions(): Promise<void> {
    const res = await api.get<SpecialOrderFilterOptions>(
        '/api/v1/special-orders/filter-options',
    );

    filterOptions.value = res.data.data;
}

async function fetchOrders(): Promise<void> {
    loading.value = true;

    errorMessage.value = null;

    try {
        const res = await api.get<PaginatedSpecialOrders>('/api/v1/special-orders', {
            params: {
                page: page.value,

                per_page: perPage.value,

                search: search.value.trim() || undefined,

                contact_media: contactMedia.value.length ? contactMedia.value : undefined,

                workflow_status: visibleWorkflowStatuses.value,

                sort_by: sortBy.value,

                sort_dir: sortDir.value,
            },
        });

        rows.value = res.data.data ?? [];

        meta.value = res.data.meta ?? meta.value;

        workflowStatusCounts.value = res.data.workflow_status_counts ?? null;
    } catch (err) {
        errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
        loading.value = false;
    }
}

function persistState(): void {
    savePageState(PAGE_STATE_KEY, {
        search: search.value,

        contactMedia: contactMedia.value,

        perPage: perPage.value,

        sortBy: sortBy.value,

        sortDir: sortDir.value,
    });
}

function restoreState(): void {
    const saved = loadPageState<{
        search?: string;

        contactMedia?: string[];

        perPage?: number;

        sortBy?: SortKey;

        sortDir?: 'asc' | 'desc';
    }>(PAGE_STATE_KEY);

    if (!saved) return;

    if (typeof saved.search === 'string') search.value = saved.search;

    if (Array.isArray(saved.contactMedia)) contactMedia.value = saved.contactMedia;

    if (typeof saved.perPage === 'number') perPage.value = saved.perPage;

    if (saved.sortBy && saved.sortBy !== 'landed' && saved.sortBy !== 'receive_delay') {
        sortBy.value = saved.sortBy === 'deposit' ? 'balance' : (saved.sortBy as SortKey);
    }

    if (saved.sortDir) sortDir.value = saved.sortDir;
}

function resetFilters(): void {
    search.value = '';

    contactMedia.value = [];

    visibleWorkflowStatuses.value = [...SPECIAL_ORDER_WORKFLOW_DEFAULT_VISIBLE];

    sortBy.value = 'created';

    sortDir.value = 'desc';

    page.value = 1;

    clearPageState(PAGE_STATE_KEY);

    clearSessionState(WORKFLOW_FILTER_SESSION_KEY);
}

watch([search, contactMedia, visibleWorkflowStatuses, perPage, sortBy, sortDir], () => {
    if (listBootstrapping) return;

    if (page.value !== 1) {
        page.value = 1;

        return;
    }

    persistState();

    persistWorkflowFilter();

    void fetchOrders();
});

watch(page, () => {
    if (listBootstrapping) return;

    persistState();

    void fetchOrders();
});

onMounted(async () => {
    restoreState();

    restoreWorkflowFilter();

    try {
        await Promise.all([loadFilterOptions(), fetchOrders()]);
    } finally {
        listBootstrapping = false;
    }
});
</script>

<template>
    <div class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Special Orders</h1>

                <p class="mt-1 text-sm text-slate-600">
                    Track customer requests and merchandiser cost quotes for Asia-sourced custom
                    orders.
                </p>
            </div>

            <RouterLink
                to="/special-orders/new"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                New order
            </RouterLink>
        </div>

        <div class="mb-4 space-y-4 rounded-lg border border-slate-200 bg-white p-4">
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-slate-700"
                        >Search contact / product / notes</span
                    >

                    <input
                        v-model="search"
                        type="search"
                        placeholder="Product name, IG handle, notes…"
                        class="w-full rounded-md border border-slate-300 px-3 py-2"
                    />
                </label>

                <div class="text-sm">
                    <span class="mb-1 block font-medium text-slate-700">Contact media</span>

                    <MultiSelectFilter
                        v-model="contactMedia"
                        :options="mediaOptions"
                        placeholder="All media"
                    />
                </div>

                <div class="flex items-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                        @click="resetFilters"
                    >
                        Reset filters
                    </button>
                </div>
            </div>

            <SpecialOrderWorkflowTimelineFilter
                v-model="visibleWorkflowStatuses"
                :status-counts="workflowStatusCounts"
            />
        </div>

        <p
            v-if="errorMessage"
            class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
            {{ errorMessage }}
        </p>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('product_name')">
                            Product {{ sortIndicator('product_name') }}
                        </th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('contact')">
                            Contact {{ sortIndicator('contact') }}
                        </th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('media')">
                            Media {{ sortIndicator('media') }}
                        </th>

                        <th class="px-3 py-2 whitespace-nowrap">Status</th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('customer_price')">
                            Price {{ sortIndicator('customer_price') }}
                        </th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('balance')">
                            Balance {{ sortIndicator('balance') }}
                        </th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('eta')">
                            ETA {{ sortIndicator('eta') }}
                        </th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('created')">
                            Created {{ sortIndicator('created') }}
                        </th>

                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('updated')">
                            Updated {{ sortIndicator('updated') }}
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-if="loading">
                        <td colspan="9" class="px-3 py-8 text-center text-slate-500">Loading…</td>
                    </tr>

                    <tr v-else-if="rows.length === 0">
                        <td colspan="9" class="px-3 py-8 text-center text-slate-500">
                            No special orders found.
                        </td>
                    </tr>

                    <tr
                        v-for="row in rows"
                        v-else
                        :key="row.id"
                        class="border-t border-slate-100 hover:bg-slate-50"
                    >
                        <td class="px-3 py-2">
                            <RouterLink
                                :to="`/special-orders/${row.id}`"
                                class="font-medium text-slate-900 hover:underline"
                            >
                                {{ row.product_name || '—' }}
                            </RouterLink>
                        </td>

                        <td class="px-3 py-2">{{ row.customer_contact_value }}</td>

                        <td class="px-3 py-2">{{ row.customer_contact_media_label }}</td>

                        <td class="px-3 py-2 whitespace-nowrap">
                            <SpecialOrderListStatusSelect
                                :order="row"
                                @updated="onRowStatusUpdated"
                                @error="onRowStatusError"
                            />
                        </td>

                        <td class="px-3 py-2">{{ formatMoney2OrEmpty(row.customer_price_cad) }}</td>

                        <td class="px-3 py-2 whitespace-nowrap">
                            <span
                                :class="
                                    specialOrderRemainingBalanceIsPaid(row)
                                        ? 'text-green-700'
                                        : ''
                                "
                            >
                                {{
                                    formatMoney2OrEmpty(specialOrderRemainingBalanceCad(row)) ||
                                    '—'
                                }}
                            </span>
                        </td>

                        <td class="px-3 py-2 whitespace-nowrap">
                            {{
                                row.estimated_arrival_at
                                    ? formatTorontoDate(row.estimated_arrival_at)
                                    : '—'
                            }}
                        </td>

                        <td class="px-3 py-2 whitespace-nowrap">
                            {{
                                row.created_at ? formatTorontoDateTimeCompact(row.created_at) : '—'
                            }}
                        </td>

                        <td class="px-3 py-2 whitespace-nowrap">
                            {{
                                row.updated_at ? formatTorontoDateTimeCompact(row.updated_at) : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
            <div>
                {{ meta.total }} order{{ meta.total === 1 ? '' : 's' }}

                <span v-if="meta.last_page > 1">
                    · page {{ meta.current_page }} / {{ meta.last_page }}</span
                >
            </div>

            <div v-if="meta.last_page > 1" class="flex gap-2">
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-1 disabled:opacity-40"
                    :disabled="page <= 1 || loading"
                    @click="page -= 1"
                >
                    Previous
                </button>

                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-1 disabled:opacity-40"
                    :disabled="page >= meta.last_page || loading"
                    @click="page += 1"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>

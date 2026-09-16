<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import ShopifyOrdersTable from '../components/shopifyOrders/ShopifyOrdersTable.vue';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';
import { loadPageState, savePageState } from '../lib/pageState';
import {
    formatShopifyOrderMoney,
    isShopifyOrderPreorderFilter,
    isShopifyOrderStatusFilter,
    shopifyOrderDefaultFromDate,
    shopifyOrderDefaultUntilDate,
    shopifyOrderMonthStart,
    torontoDateOffset,
} from '../lib/shopifyOrders';
import type { StoreEvent } from '../types/storeEvent';
import type {
    ShopifyOrder,
    ShopifyOrderChannelOption,
    ShopifyOrderIndexResponse,
    ShopifyOrderLine,
    ShopifyOrderShowResponse,
    ShopifyOrderPreorderFilter,
    ShopifyOrderSortKey,
    ShopifyOrderStatusFilter,
} from '../types/shopifyOrders';

const PAGE_STATE_KEY = 'shopify-orders:list:v1';
const DATE_QUERY = /^\d{4}-\d{2}-\d{2}$/;
const route = useRoute();

const fromDate = ref(shopifyOrderDefaultFromDate());
const untilDate = ref(shopifyOrderDefaultUntilDate());
const search = ref('');
const channel = ref('');
const status = ref<ShopifyOrderStatusFilter>('all');
const preorder = ref<ShopifyOrderPreorderFilter>('all');
const sortBy = ref<ShopifyOrderSortKey>('ordered_at');
const sortDir = ref<'asc' | 'desc'>('desc');
const page = ref(1);

const loading = ref(false);
const error = ref<string | null>(null);
const rows = ref<ShopifyOrder[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const summary = ref<ShopifyOrderIndexResponse['summary'] | null>(null);
const channelOptions = ref<ShopifyOrderChannelOption[]>([]);
const expandedId = ref<number | null>(null);
const expandedLines = ref<ShopifyOrderLine[]>([]);
const linesLoading = ref(false);
const selectedIds = ref<number[]>([]);
const storeEvents = ref<StoreEvent[]>([]);
const assignEventId = ref('');
const assigning = ref(false);
let bootstrapping = true;

const currency = computed(() => summary.value?.revenue_currency ?? 'CAD');
const lastPage = computed(() => meta.value.last_page);

function persistState(): void {
    savePageState(PAGE_STATE_KEY, {
        fromDate: fromDate.value,
        untilDate: untilDate.value,
        search: search.value,
        channel: channel.value,
        status: status.value,
        preorder: preorder.value,
        sortBy: sortBy.value,
        sortDir: sortDir.value,
        page: page.value,
    });
}

function restoreState(): void {
    const saved = loadPageState<{
        fromDate?: string;
        untilDate?: string;
        search?: string;
        channel?: string;
        status?: string;
        preorder?: string;
        sortBy?: ShopifyOrderSortKey;
        sortDir?: 'asc' | 'desc';
        page?: number;
    }>(PAGE_STATE_KEY);
    if (!saved) return;
    if (typeof saved.fromDate === 'string') fromDate.value = saved.fromDate;
    if (typeof saved.untilDate === 'string') untilDate.value = saved.untilDate;
    if (typeof saved.search === 'string') search.value = saved.search;
    if (typeof saved.channel === 'string') channel.value = saved.channel;
    if (typeof saved.status === 'string' && isShopifyOrderStatusFilter(saved.status)) {
        status.value = saved.status;
    }
    if (typeof saved.preorder === 'string' && isShopifyOrderPreorderFilter(saved.preorder)) {
        preorder.value = saved.preorder;
    }
    if (saved.sortBy) sortBy.value = saved.sortBy;
    if (saved.sortDir) sortDir.value = saved.sortDir;
    if (typeof saved.page === 'number' && saved.page > 0) page.value = saved.page;
}

function applyRouteQuery(): void {
    const preorderQ = route.query.preorder;
    if (typeof preorderQ === 'string' && isShopifyOrderPreorderFilter(preorderQ)) {
        preorder.value = preorderQ;
    }
    const statusQ = route.query.status;
    if (typeof statusQ === 'string' && isShopifyOrderStatusFilter(statusQ)) {
        status.value = statusQ;
    }
    const fromQ = route.query.from;
    if (typeof fromQ === 'string' && DATE_QUERY.test(fromQ)) {
        fromDate.value = fromQ;
    }
    const untilQ = route.query.until;
    if (typeof untilQ === 'string' && DATE_QUERY.test(untilQ)) {
        untilDate.value = untilQ;
    }
}

function applyPreset(kind: 'today' | 'week' | 'month'): void {
    untilDate.value = shopifyOrderDefaultUntilDate();
    if (kind === 'today') fromDate.value = untilDate.value;
    else if (kind === 'week') fromDate.value = torontoDateOffset(-6);
    else fromDate.value = shopifyOrderMonthStart();
}

function toggleSort(key: ShopifyOrderSortKey): void {
    if (sortBy.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = key;
        sortDir.value = key === 'name' || key === 'contact' || key === 'channel' ? 'asc' : 'desc';
    }
}

async function loadOrders(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<ShopifyOrderIndexResponse>('/api/v1/shopify/orders', {
            params: {
                from: fromDate.value,
                until: untilDate.value,
                search: search.value.trim() || undefined,
                channel: channel.value || undefined,
                status: status.value,
                preorder: preorder.value,
                sort_by: sortBy.value,
                sort_dir: sortDir.value,
                page: page.value,
                per_page: 50,
            },
        });
        rows.value = res.data.data ?? [];
        selectedIds.value = [];
        meta.value = res.data.meta ?? meta.value;
        summary.value = res.data.summary ?? null;
        if (res.data.summary?.channel_options) {
            channelOptions.value = res.data.summary.channel_options;
        }
    } catch {
        error.value = 'Failed to load Shopify orders.';
        rows.value = [];
    } finally {
        loading.value = false;
    }
}

function toggleSelect(id: number): void {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((item) => item !== id)
        : [...selectedIds.value, id];
}

function toggleSelectPage(selected: boolean): void {
    const pageIds = rows.value.map((row) => row.id);
    if (selected) {
        selectedIds.value = [...new Set([...selectedIds.value, ...pageIds])];
        return;
    }
    selectedIds.value = selectedIds.value.filter((id) => !pageIds.includes(id));
}

async function loadStoreEvents(): Promise<void> {
    try {
        const { data } = await api.get<{ data: StoreEvent[] }>('/api/v1/store-events');
        storeEvents.value = data.data ?? [];
    } catch {
        storeEvents.value = [];
    }
}

async function assignSelected(): Promise<void> {
    if (selectedIds.value.length === 0) return;
    assigning.value = true;
    error.value = null;
    try {
        await api.post('/api/v1/shopify/orders/store-event', {
            order_ids: selectedIds.value,
            store_event_id: assignEventId.value === '' ? null : assignEventId.value,
        });
        selectedIds.value = [];
        await loadOrders();
    } catch {
        error.value = 'Failed to assign orders to the event.';
    } finally {
        assigning.value = false;
    }
}

async function toggleLines(order: ShopifyOrder): Promise<void> {
    if (expandedId.value === order.id) {
        expandedId.value = null;
        expandedLines.value = [];
        return;
    }
    expandedId.value = order.id;
    expandedLines.value = [];
    linesLoading.value = true;
    try {
        const res = await api.get<ShopifyOrderShowResponse>(`/api/v1/shopify/orders/${order.id}`);
        expandedLines.value = res.data.data.lines ?? [];
    } catch {
        expandedLines.value = [];
    } finally {
        linesLoading.value = false;
    }
}

watch([fromDate, untilDate, search, channel, status, preorder, sortBy, sortDir], () => {
    if (bootstrapping) return;
    if (page.value !== 1) {
        page.value = 1;
        return;
    }
    persistState();
    void loadOrders();
});

watch(page, () => {
    if (bootstrapping) return;
    persistState();
    void loadOrders();
});

onMounted(() => {
    restoreState();
    applyRouteQuery();
    void Promise.all([loadOrders(), loadStoreEvents()]).finally(() => {
        bootstrapping = false;
    });
});
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Orders</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Shopify sales from the ERP mirror. Totals are revenue before tax (no shipping).
                    <RouterLink to="/reports/staff-orders" class="underline"
                        >Staff report</RouterLink
                    >
                </p>
            </div>
            <div class="text-right text-sm text-slate-600">
                <div>
                    {{ summary?.filtered_count ?? 0 }} orders ·
                    {{ formatShopifyOrderMoney(summary?.filtered_subtotal ?? '0.00', currency) }}
                </div>
                <div>
                    Last sync:
                    {{
                        summary?.last_synced_at
                            ? formatTorontoDateTime(summary.last_synced_at)
                            : 'never'
                    }}
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">From</span>
                <input
                    v-model="fromDate"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    type="date"
                />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Until</span>
                <input
                    v-model="untilDate"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    type="date"
                />
            </label>
            <div class="flex gap-1 pb-0.5">
                <button
                    type="button"
                    class="rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                    @click="applyPreset('today')"
                >
                    Today
                </button>
                <button
                    type="button"
                    class="rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                    @click="applyPreset('week')"
                >
                    7 days
                </button>
                <button
                    type="button"
                    class="rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50"
                    @click="applyPreset('month')"
                >
                    This month
                </button>
            </div>
            <label class="flex min-w-[10rem] flex-col gap-1 text-sm">
                <span class="text-slate-600">Search</span>
                <input
                    v-model="search"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    placeholder="#OVS-2863"
                />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Channel</span>
                <select v-model="channel" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="">All</option>
                    <option v-for="option in channelOptions" :key="option.key" :value="option.key">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Status</span>
                <select v-model="status" class="rounded-md border border-slate-200 px-2 py-1">
                    <option value="all">All</option>
                    <option value="eligible">Eligible</option>
                    <option value="cancelled">Cancelled / voided</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-600">Pre-order</span>
                <select
                    v-model="preorder"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    data-testid="orders-preorder-filter"
                >
                    <option value="all">All</option>
                    <option value="only">Has pre-order item</option>
                </select>
            </label>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
        >
            {{ error }}
        </div>

        <div
            v-if="selectedIds.length > 0"
            class="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
        >
            <span class="text-slate-700">{{ selectedIds.length }} selected</span>
            <label class="flex flex-col gap-1">
                <span class="text-slate-600">Assign to event</span>
                <select
                    v-model="assignEventId"
                    class="rounded-md border border-slate-200 px-2 py-1"
                >
                    <option value="">Clear event</option>
                    <option v-for="event in storeEvents" :key="event.id" :value="event.id">
                        {{ event.name }}
                    </option>
                </select>
            </label>
            <button
                type="button"
                class="h-8 rounded-md bg-slate-900 px-3 text-white disabled:opacity-60"
                :disabled="assigning"
                @click="assignSelected"
            >
                {{ assigning ? 'Saving…' : 'Apply' }}
            </button>
        </div>

        <ShopifyOrdersTable
            :rows="rows"
            :loading="loading"
            :page="page"
            :last-page="lastPage"
            :sort-by="sortBy"
            :sort-dir="sortDir"
            :currency="currency"
            :expanded-id="expandedId"
            :expanded-lines="expandedLines"
            :lines-loading="linesLoading"
            :selected-ids="selectedIds"
            @toggle-sort="toggleSort"
            @toggle-lines="toggleLines"
            @toggle-select="toggleSelect"
            @toggle-select-page="toggleSelectPage"
            @update:page="page = $event"
        />
    </section>
</template>

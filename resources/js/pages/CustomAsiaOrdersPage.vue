<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import { api } from '../lib/api';
import { formatTorontoDate, formatTorontoDateTime } from '../lib/datetime';
import { formatMoney2OrEmpty } from '../lib/money';
import { clearPageState, loadPageState, savePageState } from '../lib/pageState';
import type {
    CustomAsiaOrder,
    CustomAsiaOrderFilterOptions,
    PaginatedCustomAsiaOrders,
} from '../types/customAsiaOrders';
import {
    customAsiaOrderWorkflowStatusLabel,
    customAsiaOrderWorkflowStatusTailwindClass,
    resolveCustomAsiaOrderWorkflowStatus,
} from '../lib/customAsiaOrderWorkflow';

const PAGE_STATE_KEY = 'custom-asia-orders:list:v1';

type SortKey =
    | 'created'
    | 'updated'
    | 'contact'
    | 'product_name'
    | 'media'
    | 'receive_delay'
    | 'product_cost'
    | 'shipping_cost'
    | 'customer_price'
    | 'deposit'
    | 'eta';

const search = ref('');
const contactMedia = ref<string[]>([]);
const quoteStatus = ref('');
const pricingStatus = ref('');
const lifecycleStatus = ref('active');
const perPage = ref(50);
const sortBy = ref<SortKey>('created');
const sortDir = ref<'asc' | 'desc'>('desc');
const page = ref(1);

const rows = ref<CustomAsiaOrder[]>([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const filterOptions = ref<CustomAsiaOrderFilterOptions['data'] | null>(null);
const loading = ref(false);
const errorMessage = ref<string | null>(null);
let listBootstrapping = true;

const mediaOptions = computed<MultiSelectOption[]>(() =>
    (filterOptions.value?.contact_media ?? []).map((o) => ({ value: o.value, label: o.label })),
);

function workflowStatusLabel(row: CustomAsiaOrder): string {
    return customAsiaOrderWorkflowStatusLabel(resolveCustomAsiaOrderWorkflowStatus(row));
}

function workflowStatusClass(row: CustomAsiaOrder): string {
    return customAsiaOrderWorkflowStatusTailwindClass(resolveCustomAsiaOrderWorkflowStatus(row));
}

function toggleSort(key: SortKey): void {
    if (sortBy.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = key;
        sortDir.value = key === 'contact' || key === 'media' || key === 'product_name' ? 'asc' : 'desc';
    }
    page.value = 1;
}

function sortIndicator(key: SortKey): string {
    if (sortBy.value !== key) return '';
    return sortDir.value === 'asc' ? '▲' : '▼';
}

async function loadFilterOptions(): Promise<void> {
    const res = await api.get<CustomAsiaOrderFilterOptions>('/api/v1/custom-asia-orders/filter-options');
    filterOptions.value = res.data.data;
}

async function fetchOrders(): Promise<void> {
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get<PaginatedCustomAsiaOrders>('/api/v1/custom-asia-orders', {
            params: {
                page: page.value,
                per_page: perPage.value,
                search: search.value.trim() || undefined,
                contact_media: contactMedia.value.length ? contactMedia.value : undefined,
                quote_status: quoteStatus.value || undefined,
                pricing_status: pricingStatus.value || undefined,
                lifecycle_status: lifecycleStatus.value || undefined,
                sort_by: sortBy.value,
                sort_dir: sortDir.value,
            },
        });
        rows.value = res.data.data ?? [];
        meta.value = res.data.meta ?? meta.value;
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
        quoteStatus: quoteStatus.value,
        pricingStatus: pricingStatus.value,
        lifecycleStatus: lifecycleStatus.value,
        perPage: perPage.value,
        sortBy: sortBy.value,
        sortDir: sortDir.value,
    });
}

function restoreState(): void {
    const saved = loadPageState<{
        search?: string;
        contactMedia?: string[];
        quoteStatus?: string;
        pricingStatus?: string;
        lifecycleStatus?: string;
        perPage?: number;
        sortBy?: SortKey;
        sortDir?: 'asc' | 'desc';
    }>(PAGE_STATE_KEY);
    if (!saved) return;
    if (typeof saved.search === 'string') search.value = saved.search;
    if (Array.isArray(saved.contactMedia)) contactMedia.value = saved.contactMedia;
    if (typeof saved.quoteStatus === 'string') quoteStatus.value = saved.quoteStatus;
    if (typeof saved.pricingStatus === 'string') pricingStatus.value = saved.pricingStatus;
    if (typeof saved.lifecycleStatus === 'string') lifecycleStatus.value = saved.lifecycleStatus;
    if (typeof saved.perPage === 'number') perPage.value = saved.perPage;
    if (saved.sortBy && saved.sortBy !== 'landed') sortBy.value = saved.sortBy as SortKey;
    if (saved.sortDir) sortDir.value = saved.sortDir;
}

function resetFilters(): void {
    search.value = '';
    contactMedia.value = [];
    quoteStatus.value = '';
    pricingStatus.value = '';
    lifecycleStatus.value = 'active';
    sortBy.value = 'created';
    sortDir.value = 'desc';
    page.value = 1;
    clearPageState(PAGE_STATE_KEY);
}

watch([search, contactMedia, quoteStatus, pricingStatus, lifecycleStatus, perPage, sortBy, sortDir], () => {
    if (listBootstrapping) return;
    if (page.value !== 1) {
        page.value = 1;
        return;
    }
    persistState();
    void fetchOrders();
});

watch(page, () => {
    if (listBootstrapping) return;
    persistState();
    void fetchOrders();
});

onMounted(async () => {
    restoreState();
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
                <h1 class="text-2xl font-semibold text-slate-900">Custom Orders — Asia</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Track customer requests and merchandiser cost quotes for Asia-sourced custom orders.
                </p>
            </div>
            <RouterLink
                to="/custom-orders/asia/new"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                New order
            </RouterLink>
        </div>

        <div class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-2 lg:grid-cols-6">
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Search contact / product / notes</span>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Product name, IG handle, notes…"
                    class="w-full rounded-md border border-slate-300 px-3 py-2"
                />
            </label>

            <div class="text-sm">
                <span class="mb-1 block font-medium text-slate-700">Contact media</span>
                <MultiSelectFilter v-model="contactMedia" :options="mediaOptions" placeholder="All media" />
            </div>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Quote status</span>
                <select v-model="quoteStatus" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <option value="">All</option>
                    <option
                        v-for="opt in filterOptions?.quote_statuses ?? []"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </option>
                </select>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Pricing status</span>
                <select v-model="pricingStatus" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <option value="">All</option>
                    <option
                        v-for="opt in filterOptions?.pricing_statuses ?? []"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </option>
                </select>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-slate-700">Lifecycle</span>
                <select v-model="lifecycleStatus" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    <option
                        v-for="opt in filterOptions?.lifecycle_statuses ?? []"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </option>
                </select>
            </label>

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

        <p v-if="errorMessage" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
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
                        <th class="px-3 py-2">Status</th>
                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('receive_delay')">
                            Receive in {{ sortIndicator('receive_delay') }}
                        </th>
                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('customer_price')">
                            Price {{ sortIndicator('customer_price') }}
                        </th>
                        <th class="cursor-pointer px-3 py-2" @click="toggleSort('deposit')">
                            Deposit {{ sortIndicator('deposit') }}
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
                        <td colspan="10" class="px-3 py-8 text-center text-slate-500">Loading…</td>
                    </tr>
                    <tr v-else-if="rows.length === 0">
                        <td colspan="10" class="px-3 py-8 text-center text-slate-500">No custom orders found.</td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        v-else
                        :key="row.id"
                        class="border-t border-slate-100 hover:bg-slate-50"
                    >
                        <td class="px-3 py-2">
                            <RouterLink
                                :to="`/custom-orders/asia/${row.id}`"
                                class="font-medium text-slate-900 hover:underline"
                            >
                                {{ row.product_name || '—' }}
                            </RouterLink>
                        </td>
                        <td class="px-3 py-2">{{ row.customer_contact_value }}</td>
                        <td class="px-3 py-2">{{ row.customer_contact_media_label }}</td>
                        <td class="px-3 py-2">
                            <span
                                class="w-fit rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="workflowStatusClass(row)"
                            >
                                {{ workflowStatusLabel(row) }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ row.receive_delay_label ?? '—' }}</td>
                        <td class="px-3 py-2">{{ formatMoney2OrEmpty(row.customer_price_cad) }}</td>
                        <td class="px-3 py-2">
                            <template v-if="row.deposit_percent">
                                {{ row.deposit_percent }}%
                                <span
                                    v-if="row.deposit_amount_cad"
                                    class="text-slate-500"
                                >
                                    ({{ formatMoney2OrEmpty(row.deposit_amount_cad) }})
                                </span>
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.estimated_arrival_at ? formatTorontoDate(row.estimated_arrival_at) : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.created_at ? formatTorontoDateTime(row.created_at) : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.updated_at ? formatTorontoDateTime(row.updated_at) : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
            <div>
                {{ meta.total }} order{{ meta.total === 1 ? '' : 's' }}
                <span v-if="meta.last_page > 1"> · page {{ meta.current_page }} / {{ meta.last_page }}</span>
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

<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { formatTorontoDate } from '../../lib/datetime';
import { shopifyOrderAllTimeFromDate } from '../../lib/shopifyOrders';
import type { StorePreorderOrderTotals } from '../../types/storePreorder';

const props = defineProps<{
    search: string;
    status: 'all' | 'open' | 'closed';
    sortBy: 'closing' | 'opened' | 'name';
    closesOn: string;
    closingDates: string[];
    hasUnits: boolean;
    loading: boolean;
    selectedCount: number;
    bulkBusy: boolean;
    pushBusy: boolean;
    orderTotals: StorePreorderOrderTotals | null;
}>();

const ordersHref = computed(
    () => `/orders?preorder=only&status=eligible&from=${shopifyOrderAllTimeFromDate()}`,
);
const orderLabel = computed(() => {
    const n = props.orderTotals?.order_count ?? 0;

    return n === 1 ? 'preorder order' : 'preorder orders';
});
const unitLabel = computed(() => {
    const n = props.orderTotals?.unit_qty ?? 0;

    return n === 1 ? 'unit' : 'units';
});

const emit = defineEmits<{
    (e: 'update:search', value: string): void;
    (e: 'update:status', value: 'all' | 'open' | 'closed'): void;
    (e: 'update:sortBy', value: 'closing' | 'opened' | 'name'): void;
    (e: 'update:closesOn', value: string): void;
    (e: 'update:hasUnits', value: boolean): void;
    (e: 'search'): void;
    (e: 'clear-selection'): void;
    (e: 'bulk-edit'): void;
    (e: 'bulk-delete'): void;
    (e: 'push-shopify'): void;
    (e: 'add-offer'): void;
}>();
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
                    Store preorders
                </h1>
                <p class="text-sm text-slate-600">
                    Customer store pre-order offers from the Plamod pick list or
                    <span class="font-medium">Add offer</span> (other shops). Opening queues a
                    Shopify publish to /collections/pre-orders.
                    <span class="font-medium">Push to store</span> is a retry.
                </p>
                <p
                    v-if="orderTotals"
                    class="text-sm text-slate-800"
                    data-testid="store-preorders-order-totals"
                >
                    <RouterLink
                        :to="ordersHref"
                        class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600"
                    >
                        {{ orderTotals.unit_qty }} {{ unitLabel }}
                    </RouterLink>
                    <span class="mt-0.5 block text-xs text-slate-500">
                        {{ orderTotals.order_count }} {{ orderLabel }} (not cancelled)
                    </span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 hover:bg-slate-100 disabled:opacity-50"
                    data-testid="store-preorders-add-offer"
                    :disabled="loading"
                    @click="emit('add-offer')"
                >
                    Add offer
                </button>
                <button
                    type="button"
                    class="inline-flex h-9 items-center rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    data-testid="store-preorders-push-shopify"
                    :disabled="pushBusy || loading"
                    @click="emit('push-shopify')"
                >
                    {{ pushBusy ? 'Pushing…' : 'Push to store' }}
                </button>
                <a
                    href="/preorders"
                    class="inline-flex h-9 items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 text-sm font-medium text-emerald-900 hover:bg-emerald-100"
                >
                    Select Plamod kits
                </a>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label for="store-preorders-search" class="text-xs font-medium text-slate-600"
                    >Search</label
                >
                <input
                    id="store-preorders-search"
                    :value="search"
                    type="text"
                    placeholder="SKU or name…"
                    class="h-9 w-[280px] rounded-md border border-slate-300 bg-white px-2 text-sm"
                    @input="emit('update:search', ($event.target as HTMLInputElement).value)"
                    @keydown.enter.prevent="emit('search')"
                />
            </div>
            <button
                type="button"
                class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:opacity-60"
                :disabled="loading"
                @click="emit('search')"
            >
                {{ loading ? 'Loading…' : 'Search' }}
            </button>
            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                Status
                <select
                    :value="status"
                    class="h-9 rounded-md border border-slate-300 bg-white px-2 text-sm font-normal text-slate-800"
                    data-testid="store-preorders-status"
                    @change="
                        emit(
                            'update:status',
                            ($event.target as HTMLSelectElement).value as 'all' | 'open' | 'closed',
                        )
                    "
                >
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                    <option value="all">All</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                Closing
                <select
                    :value="closesOn"
                    class="h-9 rounded-md border border-slate-300 bg-white px-2 text-sm font-normal text-slate-800"
                    data-testid="store-preorders-closes-on"
                    @change="emit('update:closesOn', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">All dates</option>
                    <option v-for="date in closingDates" :key="date" :value="date">
                        {{ formatTorontoDate(date) }}
                    </option>
                </select>
            </label>
            <label
                class="flex h-9 items-center gap-2 text-sm font-medium text-slate-700"
                data-testid="store-preorders-has-units-label"
            >
                <input
                    type="checkbox"
                    class="rounded border-slate-300"
                    :checked="hasUnits"
                    data-testid="store-preorders-has-units"
                    @change="emit('update:hasUnits', ($event.target as HTMLInputElement).checked)"
                />
                Units &gt; 0
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                Sort
                <select
                    :value="sortBy"
                    class="h-9 rounded-md border border-slate-300 bg-white px-2 text-sm font-normal text-slate-800"
                    data-testid="store-preorders-sort"
                    @change="
                        emit(
                            'update:sortBy',
                            ($event.target as HTMLSelectElement).value as
                                'closing' | 'opened' | 'name',
                        )
                    "
                >
                    <option value="closing">Closing soon</option>
                    <option value="opened">Opened</option>
                    <option value="name">Name</option>
                </select>
            </label>
        </div>

        <div
            v-if="selectedCount > 0"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
        >
            <span class="text-slate-700">
                <span class="font-semibold">{{ selectedCount }}</span> selected
            </span>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-800 hover:bg-slate-100"
                    @click="emit('clear-selection')"
                >
                    Clear
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    data-testid="store-preorders-bulk-edit"
                    :disabled="bulkBusy"
                    @click="emit('bulk-edit')"
                >
                    Edit selected
                </button>
                <button
                    type="button"
                    class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 font-medium text-rose-800 hover:bg-rose-100 disabled:opacity-50"
                    data-testid="store-preorders-bulk-delete"
                    :disabled="bulkBusy"
                    @click="emit('bulk-delete')"
                >
                    Delete selected
                </button>
            </div>
        </div>
    </div>
</template>

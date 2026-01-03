<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';
import { formatMoney2OrEmpty } from '../lib/money';

type InventoryCheckItem = {
    id: number;
    product_id: string | null;
    handle: string | null;
    vendor: string | null;
    sku: string;
    type: string | null;
    product_name: string | null;
    english_name: string | null;
    available_amount: number | null;
    selling_price: string | null;
    quantity_in_store: number | null;
    difference: number | null;
    notes: string | null;
    match_status: 'matched' | 'unmatched' | 'ambiguous';
    match_error: string | null;
    applied: boolean;
    applied_at: string | null;
};

type InventoryCheck = {
    id: string;
    name: string | null;
    source: string | null;
    uploaded_file_path: string | null;
    counts: {
        items: number;
        matched: number;
        unmatched: number;
        ambiguous: number;
        applied: number;
    };
    items: InventoryCheckItem[];
    created_at: string | null;
};

const route = useRoute();
const id = computed(() => String(route.params.id ?? ''));

const loading = ref(false);
const error = ref<string | null>(null);
const check = ref<InventoryCheck | null>(null);

const filter = ref<'all' | 'applied' | 'unapplied' | 'unmatched' | 'ambiguous'>('all');
const search = ref('');

const filteredItems = computed(() => {
    if (!check.value) return [];
    const q = search.value.trim().toLowerCase();
    return check.value.items.filter((it) => {
        if (filter.value === 'applied' && !it.applied) return false;
        if (filter.value === 'unapplied' && it.applied) return false;
        if (filter.value === 'unmatched' && it.match_status !== 'unmatched') return false;
        if (filter.value === 'ambiguous' && it.match_status !== 'ambiguous') return false;

        if (q === '') return true;
        const hay = `${it.handle ?? ''} ${it.vendor ?? ''} ${it.sku} ${it.product_name ?? ''} ${it.english_name ?? ''} ${it.notes ?? ''}`.toLowerCase();
        return hay.includes(q);
    });
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{ data: InventoryCheck }>(`/api/v1/inventory-check/${id.value}`);
        check.value = res.data.data;
    } catch {
        error.value = 'Failed to load inventory check session.';
    } finally {
        loading.value = false;
    }
}

function downloadCsv(): void {
    window.location.assign(`/api/v1/inventory-check/${id.value}/download`);
}

watch(id, () => {
    void load();
});

onMounted(() => {
    void load();
});
</script>

<template>
    <main class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Inventory Check Detail</h1>
                <p class="mt-1 text-sm text-slate-600">
                    <a class="underline underline-offset-2" href="/inventory-check">Back to history</a>
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="loading"
                @click="downloadCsv"
            >
                Download uploaded CSV
            </button>
        </div>

        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
        <p v-else-if="loading" class="text-sm text-slate-600">Loading…</p>

        <div v-else-if="check" class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-sm text-slate-800">
                    <div><span class="font-medium">ID:</span> {{ check.id }}</div>
                    <div><span class="font-medium">Created:</span> {{ formatTorontoDateTime(check.created_at) }}</div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm text-slate-800 sm:grid-cols-5">
                    <div><span class="font-medium">Rows:</span> {{ check.counts.items }}</div>
                    <div><span class="font-medium">Matched:</span> {{ check.counts.matched }}</div>
                    <div><span class="font-medium">Applied:</span> {{ check.counts.applied }}</div>
                    <div><span class="font-medium">Unmatched:</span> {{ check.counts.unmatched }}</div>
                    <div><span class="font-medium">Ambiguous:</span> {{ check.counts.ambiguous }}</div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex-1">
                        <label class="text-xs font-medium text-slate-700">Search</label>
                        <input
                            v-model="search"
                            type="text"
                            class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            placeholder="Handle, SKU, vendor, notes…"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-700">Filter</label>
                        <select
                            v-model="filter"
                            class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        >
                            <option value="all">All</option>
                            <option value="applied">Applied</option>
                            <option value="unapplied">Not applied</option>
                            <option value="unmatched">Unmatched</option>
                            <option value="ambiguous">Ambiguous</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="text-slate-600">
                            <tr>
                                <th class="px-2 py-1">Handle</th>
                                <th class="px-2 py-1">Vendor</th>
                                <th class="px-2 py-1">SKU</th>
                                <th class="px-2 py-1">Type</th>
                                <th class="px-2 py-1">Product Name</th>
                                <th class="px-2 py-1 text-right">Available amount</th>
                                <th class="px-2 py-1 text-right">Selling price</th>
                                <th class="px-2 py-1 text-right">Qty in store</th>
                                <th class="px-2 py-1 text-right">Difference</th>
                                <th class="px-2 py-1">Notes</th>
                                <th class="px-2 py-1">Error</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-800">
                            <tr v-for="it in filteredItems" :key="it.id" class="border-t border-slate-200">
                                <td class="px-2 py-1">{{ it.handle ?? '' }}</td>
                                <td class="px-2 py-1">{{ it.vendor ?? '' }}</td>
                                <td class="px-2 py-1">{{ it.sku }}</td>
                                <td class="px-2 py-1">{{ it.type ?? '' }}</td>
                                <td class="px-2 py-1">{{ it.english_name || it.product_name || '' }}</td>
                                <td class="px-2 py-1 text-right">{{ it.available_amount ?? '' }}</td>
                                <td class="px-2 py-1 text-right">{{ formatMoney2OrEmpty(it.selling_price) }}</td>
                                <td class="px-2 py-1 text-right">{{ it.quantity_in_store ?? '' }}</td>
                                <td class="px-2 py-1 text-right">{{ it.difference ?? '' }}</td>
                                <td class="px-2 py-1">{{ it.notes ?? '' }}</td>
                                <td class="px-2 py-1 text-slate-600">{{ it.match_error ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="filteredItems.length === 0" class="mt-3 text-sm text-slate-600">No rows match your filters.</p>
                </div>
            </section>
        </div>
    </main>
</template>





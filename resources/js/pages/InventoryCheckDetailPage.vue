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
    workflow_state?: string | null;
    created_by_role?: string | null;
    applied_at?: string | null;
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
const applying = ref(false);
const applyQuantity = ref(true);
const applyName = ref(true);
const savingLine = ref<Record<number, true>>({});
const quantityDrafts = ref<Record<number, string>>({});
const nameDrafts = ref<Record<number, string>>({});

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

async function applySession(): Promise<void> {
    if (!check.value) return;
    if (applying.value) return;

    const msg = `Apply selected fields for this session? Only products present in this session are updated.`;
    if (!window.confirm(msg)) return;

    applying.value = true;
    error.value = null;
    try {
        await api.post(`/api/v1/inventory-check/${id.value}/apply`, {
            apply_quantity: applyQuantity.value,
            apply_name: applyName.value,
        });
        await load();
    } catch {
        error.value = 'Failed to apply this session.';
    } finally {
        applying.value = false;
    }
}

function isSavingLine(itemId: number): boolean {
    return savingLine.value[itemId] === true;
}

function isBarcodeNotFoundRow(item: InventoryCheckItem): boolean {
    const err = item.match_error ?? '';

    return item.match_status === 'unmatched' && err.includes('No active product found');
}

function qtyValueFor(item: InventoryCheckItem): string {
    if (quantityDrafts.value[item.id] !== undefined) return quantityDrafts.value[item.id]!;

    return item.quantity_in_store === null ? '' : String(item.quantity_in_store);
}

function nameValueFor(item: InventoryCheckItem): string {
    if (nameDrafts.value[item.id] !== undefined) return nameDrafts.value[item.id]!;

    return item.product_name || item.english_name || '';
}

async function saveLine(item: InventoryCheckItem): Promise<void> {
    if (isSavingLine(item.id)) return;

    const qRaw = quantityDrafts.value[item.id] ?? (item.quantity_in_store === null ? '' : String(item.quantity_in_store));
    const nameRaw = nameDrafts.value[item.id] ?? (item.product_name || item.english_name || '');
    const qtyParsed = Number.parseInt(qRaw.trim() === '' ? '0' : qRaw, 10);
    const quantity = Number.isFinite(qtyParsed) && qtyParsed >= 0 ? qtyParsed : 0;
    const productName = nameRaw.trim();

    savingLine.value = { ...savingLine.value, [item.id]: true };
    error.value = null;
    try {
        await api.patch(`/api/v1/inventory-check/${id.value}/items/${item.id}`, {
            quantity,
            product_name: productName,
        });
        await load();
        const { [item.id]: _qd, ...restQ } = quantityDrafts.value;
        quantityDrafts.value = restQ;
        const { [item.id]: _nd, ...restN } = nameDrafts.value;
        nameDrafts.value = restN;
    } catch {
        error.value = 'Failed to save line changes.';
    } finally {
        const { [item.id]: _omit, ...rest } = savingLine.value;
        savingLine.value = rest;
    }
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
            <div class="flex items-center gap-2">
                <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                    <input v-model="applyQuantity" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                    Apply quantity
                </label>
                <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                    <input v-model="applyName" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                    Apply name
                </label>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="loading || applying || (!applyQuantity && !applyName)"
                    @click="applySession"
                >
                    {{ applying ? 'Applying…' : 'Apply quantities' }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="loading"
                    @click="downloadCsv"
                >
                    Download uploaded CSV
                </button>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
        <p v-else-if="loading" class="text-sm text-slate-600">Loading…</p>

        <div v-else-if="check" class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-sm text-slate-800">
                    <div><span class="font-medium">ID:</span> {{ check.id }}</div>
                    <div><span class="font-medium">Created:</span> {{ formatTorontoDateTime(check.created_at) }}</div>
                    <div><span class="font-medium">State:</span> {{ check.workflow_state ?? 'draft' }}</div>
                    <div><span class="font-medium">Created by:</span> {{ check.created_by_role ?? '—' }}</div>
                    <div><span class="font-medium">Applied at:</span> {{ formatTorontoDateTime(check.applied_at) || '—' }}</div>
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
                <p class="mt-2 text-xs text-slate-500">
                    Product name and quantity save automatically when you leave the field.
                </p>

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
                            <tr
                                v-for="it in filteredItems"
                                :key="it.id"
                                :class="[
                                    'border-t border-slate-200',
                                    isBarcodeNotFoundRow(it)
                                        ? 'border-l-4 border-l-red-600 bg-red-100 hover:bg-red-200/90'
                                        : 'hover:bg-slate-50',
                                ]"
                            >
                                <td class="px-2 py-1">{{ it.handle ?? '' }}</td>
                                <td class="px-2 py-1">{{ it.vendor ?? '' }}</td>
                                <td class="px-2 py-1">{{ it.sku }}</td>
                                <td class="px-2 py-1">{{ it.type ?? '' }}</td>
                                <td class="px-2 py-1">
                                    <input
                                        class="w-64 rounded border border-slate-200 px-2 py-1 text-xs"
                                        type="text"
                                        :disabled="isSavingLine(it.id)"
                                        :value="nameValueFor(it)"
                                        @input="
                                            nameDrafts = {
                                                ...nameDrafts,
                                                [it.id]: ($event.target as HTMLInputElement).value,
                                            }
                                        "
                                        @change="saveLine(it)"
                                    />
                                </td>
                                <td class="px-2 py-1 text-right">{{ it.available_amount ?? '' }}</td>
                                <td class="px-2 py-1 text-right">{{ formatMoney2OrEmpty(it.selling_price) }}</td>
                                <td class="px-2 py-1 text-right">
                                    <input
                                        class="w-20 rounded border border-slate-200 px-2 py-1 text-right text-xs"
                                        type="number"
                                        min="0"
                                        :disabled="isSavingLine(it.id)"
                                        :value="qtyValueFor(it)"
                                        @input="
                                            quantityDrafts = {
                                                ...quantityDrafts,
                                                [it.id]: ($event.target as HTMLInputElement).value,
                                            }
                                        "
                                        @change="saveLine(it)"
                                    />
                                </td>
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





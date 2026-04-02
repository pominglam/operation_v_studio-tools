<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';
import { formatMoney2OrEmpty, parseMoney } from '../lib/money';

type PurchaseOrderListRow = {
    id: string;
    is_done: boolean;
    vendor: string;
    ordered_date: string | null;
    shipped_date: string | null;
    estimated_arrival_date: string | null;
    received_date: string | null;
    fully_on_shelves_date: string | null;
    shipping_total: string | null;
    surcharge_total: string | null;
    product_total: string | null;
    notes: string | null;
    counts: { items: number };
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

type ImportResult = {
    purchase_order_uuid: string;
    items: number;
    lots: number;
    shipping_per_unit: string | null;
};

type FilterOptions = {
    data: {
        vendors: string[];
    };
};

const DEFAULT_VENDOR_OPTIONS = ['Plamod', 'Dspiae', 'Stedi', 'Gaahleri', 'MSMN', 'PM', 'JS'] as const;

const loading = ref(false);
const error = ref<string | null>(null);
const pos = ref<PurchaseOrderListRow[]>([]);
const meta = ref<Paginated<PurchaseOrderListRow>['meta'] | null>(null);

type PurchaseOrderSortBy = 'created' | 'ordered';
const sortBy = ref<PurchaseOrderSortBy>('created');
const sortDir = ref<'asc' | 'desc'>('desc');
const savingDoneById = ref<Record<string, true>>({});

const file = ref<File | null>(null);
const importing = ref(false);
const importError = ref<string | null>(null);
const importIssues = ref<Array<Record<string, unknown>> | null>(null);
const importResult = ref<ImportResult | null>(null);

const vendors = ref<string[]>([]);
const vendor = ref<string>('');
const orderedDate = ref<string>('');
const shippedDate = ref<string>('');
const estimatedArrivalDate = ref<string>('');
const receivedDate = ref<string>('');
const fullyOnShelvesDate = ref<string>('');
const productTotal = ref<string>('');
const shippingTotal = ref<string>('');
const surchargeTotal = ref<string>('');
const notes = ref<string>('');

const hasImportResult = computed(() => importResult.value !== null);

function mergeVendorOptions(next: string[]): void {
    const normalized = new Map<string, string>();
    for (const raw of [...vendors.value, ...next]) {
        const value = String(raw ?? '').trim();
        if (value === '') continue;
        const key = value.toLowerCase();
        if (!normalized.has(key)) {
            normalized.set(key, value);
        }
    }

    const merged = Array.from(normalized.values()).sort((a, b) => a.localeCompare(b));
    vendors.value = merged;
    // Keep vendor editable/blank by default so datalist can show all options.
    // (A prefilled value causes native datalist UIs to appear single-option filtered.)
    if (vendor.value.trim() !== '' && !merged.includes(vendor.value)) {
        vendor.value = '';
    }
}

async function loadVendors(): Promise<void> {
    let hadSuccess = false;
    try {
        const res = await api.get<FilterOptions>('/api/v1/purchase-orders/filter-options');
        mergeVendorOptions(res.data.data.vendors ?? []);
        hadSuccess = true;
    } catch {
        // fallback to products API
    }

    try {
        const res = await api.get<FilterOptions>('/api/v1/products/filter-options');
        mergeVendorOptions(res.data.data.vendors ?? []);
        hadSuccess = true;
    } catch {
        if (!hadSuccess) {
            vendors.value = [];
        }
    }
}

async function sniffVendorFromCsv(f: File): Promise<string | null> {
    try {
        const head = (await f.slice(0, 4096).text()).toUpperCase();
        if (head.includes('DSPIAE')) return 'Dspiae';
        return null;
    } catch {
        return null;
    }
}

async function loadHistory(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<Paginated<PurchaseOrderListRow>>('/api/v1/purchase-orders', {
            params: { per_page: 50, sort_by: sortBy.value, sort_dir: sortDir.value },
        });
        pos.value = res.data.data;
        meta.value = res.data.meta;
        mergeVendorOptions(
            pos.value
                .map((x) => String(x.vendor ?? '').trim())
                .filter((x) => x !== ''),
        );
    } catch {
        error.value = 'Failed to load purchase orders.';
    } finally {
        loading.value = false;
    }
}

function toggleCreatedSort(): void {
    if (sortBy.value !== 'created') {
        sortBy.value = 'created';
        sortDir.value = 'desc';
        void loadHistory();
        return;
    }
    sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    void loadHistory();
}

function createdSortIndicator(): string {
    if (sortBy.value !== 'created') return '';
    return sortDir.value === 'asc' ? ' ▲' : ' ▼';
}

function toggleOrderedSort(): void {
    if (sortBy.value !== 'ordered') {
        sortBy.value = 'ordered';
        sortDir.value = 'desc';
        void loadHistory();
        return;
    }
    sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    void loadHistory();
}

function orderedSortIndicator(): string {
    if (sortBy.value !== 'ordered') return '';
    return sortDir.value === 'asc' ? ' ▲' : ' ▼';
}

function poTotal(po: PurchaseOrderListRow): string | null {
    const p = parseMoney(po.product_total);
    const s = parseMoney(po.shipping_total);
    const sur = parseMoney(po.surcharge_total);
    if (p === null && s === null && sur === null) return null;
    const total = (p ?? 0) + (s ?? 0) + (sur ?? 0);
    return total.toFixed(2);
}

function isSavingDone(id: string): boolean {
    return savingDoneById.value[id] === true;
}

async function toggleDone(id: string, next: boolean): Promise<void> {
    const row = pos.value.find((p) => p.id === id);
    if (!row || isSavingDone(id)) {
        return;
    }

    const prev = Boolean(row.is_done);
    row.is_done = next;
    savingDoneById.value = { ...savingDoneById.value, [id]: true };

    try {
        const res = await api.patch(
            `/api/v1/purchase-orders/${id}`,
            { is_done: next },
            { validateStatus: () => true },
        );
        if (res.status < 200 || res.status >= 300) {
            row.is_done = prev;
            error.value = res.data?.message ?? 'Failed to update done flag.';
            return;
        }
        const saved = (res.data?.data?.is_done as boolean | null | undefined) ?? next;
        row.is_done = Boolean(saved);
    } catch {
        row.is_done = prev;
        error.value = 'Failed to update done flag.';
    } finally {
        const { [id]: _omit, ...rest } = savingDoneById.value;
        savingDoneById.value = rest;
    }
}

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    if (file.value) {
        void (async () => {
            const detected = await sniffVendorFromCsv(file.value!);
            if (detected) vendor.value = detected;
        })();
    }
}

async function runImport(): Promise<void> {
    importError.value = null;
    importIssues.value = null;
    importResult.value = null;

    if (!file.value) {
        importError.value = 'Please choose a CSV file.';
        return;
    }

    importing.value = true;
    try {
        const fd = new FormData();
        fd.append('file', file.value);
        fd.append('vendor', vendor.value);
        if (orderedDate.value) fd.append('ordered_date', orderedDate.value);
        if (shippedDate.value) fd.append('shipped_date', shippedDate.value);
        if (estimatedArrivalDate.value) fd.append('estimated_arrival_date', estimatedArrivalDate.value);
        if (receivedDate.value) fd.append('received_date', receivedDate.value);
        if (fullyOnShelvesDate.value) fd.append('fully_on_shelves_date', fullyOnShelvesDate.value);
        if (productTotal.value) fd.append('product_total', productTotal.value);
        if (shippingTotal.value) fd.append('shipping_total', shippingTotal.value);
        if (surchargeTotal.value) fd.append('surcharge_total', surchargeTotal.value);
        if (notes.value) fd.append('notes', notes.value);

        const res = await api.post<ImportResult>('/api/v1/purchase-orders/import', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        importResult.value = res.data;
        await loadHistory();
    } catch (e: unknown) {
        const anyErr = e as any;
        const apiMessage: string | undefined = anyErr?.response?.data?.message;
        const apiIssues: unknown = anyErr?.response?.data?.issues;
        importError.value = apiMessage ?? 'Import failed. Check the CSV format and try again.';
        importIssues.value = Array.isArray(apiIssues) ? (apiIssues as Array<Record<string, unknown>>) : null;
    } finally {
        importing.value = false;
    }
}

onMounted(() => {
    mergeVendorOptions([...DEFAULT_VENDOR_OPTIONS]);
    void loadVendors();
    void loadHistory();
});
</script>

<template>
    <main class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-slate-900">Purchase Orders</h1>
            <p class="mt-1 text-sm text-slate-600">
                Import a vendor PO CSV, enter shipping total, and track FIFO lots for landed costing.
            </p>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Create / Import</h2>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6">
                <div class="lg:col-span-2">
                    <label class="text-xs font-medium text-slate-700">Vendor</label>
                    <input
                        v-model="vendor"
                        list="vendor-options"
                        type="text"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="Select or type vendor…"
                    />
                    <datalist id="vendor-options">
                        <option v-for="v in vendors" :key="v" :value="v" />
                    </datalist>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Ordered</label>
                    <input v-model="orderedDate" type="date" class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Shipped</label>
                    <input v-model="shippedDate" type="date" class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Received</label>
                    <input v-model="receivedDate" type="date" class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Estimated arrival</label>
                    <input v-model="estimatedArrivalDate" type="date" class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">On shelves</label>
                    <input
                        v-model="fullyOnShelvesDate"
                        type="date"
                        class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6 lg:items-end">
                <div>
                    <label class="text-xs font-medium text-slate-700">Product total (CAD)</label>
                    <input
                        v-model="productTotal"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="0.00"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Shipping total</label>
                    <input
                        v-model="shippingTotal"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="0.00"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700">Surcharge total</label>
                    <input
                        v-model="surchargeTotal"
                        type="text"
                        inputmode="decimal"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="0.00"
                    />
                </div>
                <div class="lg:col-span-3">
                    <label class="text-xs font-medium text-slate-700">Notes</label>
                    <input
                        v-model="notes"
                        type="text"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="Optional"
                    />
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6 lg:items-end">
                <div class="lg:col-span-5">
                    <label class="text-xs font-medium text-slate-700">CSV file</label>
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        @change="onFileChange"
                    />
                </div>
                <div class="lg:col-span-1">
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="importing"
                        @click="runImport"
                    >
                        {{ importing ? 'Importing…' : 'Import CSV' }}
                    </button>
                </div>
            </div>

            <p v-if="importError" class="mt-3 text-sm text-red-700">{{ importError }}</p>

            <div
                v-if="importIssues && importIssues.length"
                class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                <div class="font-medium">Import blocked. Fix these issues, then retry:</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li v-for="(issue, idx) in importIssues" :key="idx">
                        <code class="text-xs">{{ issue }}</code>
                    </li>
                </ul>
            </div>

            <div v-if="hasImportResult" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">
                <div>
                    <span class="font-medium">PO:</span>
                    <a class="underline underline-offset-2" :href="`/purchase-orders/${importResult!.purchase_order_uuid}`">
                        {{ importResult!.purchase_order_uuid }}
                    </a>
                </div>
                <div class="mt-1 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-4">
                    <div><span class="font-medium">Items:</span> {{ importResult!.items }}</div>
                    <div><span class="font-medium">Lots:</span> {{ importResult!.lots }}</div>
                    <div><span class="font-medium">Ship/unit:</span> {{ importResult!.shipping_per_unit ?? '—' }}</div>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">History</h2>
                <button
                    type="button"
                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    :disabled="loading"
                    @click="loadHistory"
                >
                    Refresh
                </button>
            </div>

            <p v-if="error" class="mt-3 text-sm text-red-700">{{ error }}</p>
            <p v-else-if="loading" class="mt-3 text-sm text-slate-600">Loading…</p>

            <div v-else class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-slate-600">
                        <tr>
                            <th class="px-2 py-2">ID</th>
                            <th class="px-2 py-2 text-center">Done</th>
                            <th class="px-2 py-2">
                                <button type="button" class="hover:underline" data-testid="po-history-sort-created" @click="toggleCreatedSort">
                                    Created{{ createdSortIndicator() }}
                                </button>
                            </th>
                            <th class="px-2 py-2">
                                <button type="button" class="hover:underline" data-testid="po-history-sort-ordered" @click="toggleOrderedSort">
                                    Ordered{{ orderedSortIndicator() }}
                                </button>
                            </th>
                            <th class="px-2 py-2">Estimated arrival</th>
                            <th class="px-2 py-2">Received</th>
                            <th class="px-2 py-2">On shelves</th>
                            <th class="px-2 py-2">Vendor</th>
                            <th class="px-2 py-2 text-right">Items</th>
                            <th class="px-2 py-2 text-right">Product total</th>
                            <th class="px-2 py-2 text-right">Shipping total</th>
                            <th class="px-2 py-2 text-right">Surcharge total</th>
                            <th class="px-2 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-800">
                        <tr v-for="po in pos" :key="po.id" class="border-t border-slate-200 hover:bg-slate-50">
                            <td class="px-2 py-2">
                                <a class="underline underline-offset-2" :href="`/purchase-orders/${po.id}`">{{ po.id }}</a>
                            </td>
                            <td class="px-2 py-2 text-center">
                                <input
                                    :checked="Boolean(po.is_done)"
                                    :disabled="isSavingDone(po.id)"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-slate-900"
                                    :data-testid="`po-history-done:${po.id}`"
                                    @change="toggleDone(po.id, ($event.target as HTMLInputElement).checked)"
                                />
                            </td>
                            <td class="px-2 py-2 text-slate-600">{{ formatTorontoDateTime(po.created_at) }}</td>
                            <td class="px-2 py-2">{{ po.ordered_date ?? '—' }}</td>
                            <td class="px-2 py-2">{{ po.estimated_arrival_date ?? '—' }}</td>
                            <td class="px-2 py-2">{{ po.received_date ?? '—' }}</td>
                            <td class="px-2 py-2">{{ po.fully_on_shelves_date ?? '—' }}</td>
                            <td class="px-2 py-2">{{ po.vendor }}</td>
                            <td class="px-2 py-2 text-right">{{ po.counts.items }}</td>
                            <td class="px-2 py-2 text-right">{{ formatMoney2OrEmpty(po.product_total) }}</td>
                            <td class="px-2 py-2 text-right">{{ formatMoney2OrEmpty(po.shipping_total) }}</td>
                            <td class="px-2 py-2 text-right">{{ formatMoney2OrEmpty(po.surcharge_total) }}</td>
                            <td class="px-2 py-2 text-right">{{ formatMoney2OrEmpty(poTotal(po)) }}</td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="meta && meta.total === 0" class="mt-3 text-sm text-slate-600">No purchase orders yet.</p>
            </div>
        </section>
    </main>
</template>



<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';

type InventoryCheckListRow = {
    id: string;
    name: string | null;
    source: string | null;
    created_at: string | null;
    counts: {
        items: number;
        matched: number;
        unmatched: number;
        ambiguous: number;
        applied: number;
    };
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
    inventory_check: { id: number; uuid: string };
    uploaded_file_path: string;
    rows_parsed: number;
    matched: number;
    applied: number;
    not_applied: number;
    unmatched: number;
    ambiguous: number;
    not_applied_rows: Array<{ handle: string; vendor: string; sku: string; reason: string }>;
    unmatched_rows: Array<{ handle: string; vendor: string; sku: string; reason: string }>;
    ambiguous_rows: Array<{ handle: string; vendor: string; sku: string; reason: string }>;
};

const loading = ref(false);
const error = ref<string | null>(null);
const checks = ref<InventoryCheckListRow[]>([]);
const meta = ref<Paginated<InventoryCheckListRow>['meta'] | null>(null);

const file = ref<File | null>(null);
const importing = ref(false);
const importError = ref<string | null>(null);
const importResult = ref<ImportResult | null>(null);

const hasImportResult = computed(() => importResult.value !== null);

async function loadHistory(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<Paginated<InventoryCheckListRow>>('/api/v1/inventory-check', {
            params: { per_page: 50 },
        });
        checks.value = res.data.data;
        meta.value = res.data.meta;
    } catch {
        error.value = 'Failed to load inventory check history.';
    } finally {
        loading.value = false;
    }
}

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
}

async function runImport(): Promise<void> {
    importError.value = null;
    importResult.value = null;

    if (!file.value) {
        importError.value = 'Please choose a CSV file.';
        return;
    }

    importing.value = true;
    try {
        const fd = new FormData();
        fd.append('file', file.value);
        const res = await api.post<ImportResult>('/api/v1/products/import-inventory-check', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        importResult.value = res.data;
        await loadHistory();
    } catch (e: unknown) {
        importError.value = 'Import failed. Please check the CSV format and try again.';
    } finally {
        importing.value = false;
    }
}
</script>

<template>
    <main class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-slate-900">Inventory Check</h1>
            <p class="mt-1 text-sm text-slate-600">
                Import a counted CSV to update <span class="font-medium">Available Qty</span> and store differences/notes as a
                historical session.
            </p>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Create / Import</h2>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="text-xs font-medium text-slate-700">CSV file</label>
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        @change="onFileChange"
                    />
                </div>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="importing"
                    @click="runImport"
                >
                    {{ importing ? 'Importing…' : 'Import CSV' }}
                </button>
            </div>

            <p v-if="importError" class="mt-3 text-sm text-red-700">{{ importError }}</p>

            <div v-if="hasImportResult" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="flex flex-col gap-1 text-sm text-slate-800">
                    <div>
                        <span class="font-medium">Session:</span>
                        <a
                            class="text-slate-900 underline underline-offset-2"
                            :href="`/inventory-check/${importResult!.inventory_check.uuid}`"
                        >
                            {{ importResult!.inventory_check.uuid }}
                        </a>
                    </div>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-6">
                        <div><span class="font-medium">Rows:</span> {{ importResult!.rows_parsed }}</div>
                        <div><span class="font-medium">Matched:</span> {{ importResult!.matched }}</div>
                        <div><span class="font-medium">Applied:</span> {{ importResult!.applied }}</div>
                        <div><span class="font-medium">Needs attention:</span> {{ importResult!.not_applied }}</div>
                        <div><span class="font-medium">Unmatched:</span> {{ importResult!.unmatched }}</div>
                        <div><span class="font-medium">Ambiguous:</span> {{ importResult!.ambiguous }}</div>
                    </div>
                </div>

                <div v-if="importResult!.not_applied_rows.length > 0" class="mt-4">
                    <h3 class="text-xs font-semibold text-slate-900">Needs attention (quantity in store)</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="text-slate-600">
                                <tr>
                                    <th class="px-2 py-1">Handle</th>
                                    <th class="px-2 py-1">Vendor</th>
                                    <th class="px-2 py-1">SKU</th>
                                    <th class="px-2 py-1">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="text-slate-800">
                                <tr v-for="(r, idx) in importResult!.not_applied_rows" :key="idx" class="border-t border-slate-200">
                                    <td class="px-2 py-1">{{ r.handle }}</td>
                                    <td class="px-2 py-1">{{ r.vendor }}</td>
                                    <td class="px-2 py-1">{{ r.sku }}</td>
                                    <td class="px-2 py-1">{{ r.reason }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="importResult!.unmatched_rows.length > 0" class="mt-4">
                    <h3 class="text-xs font-semibold text-slate-900">Unmatched rows</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="text-slate-600">
                                <tr>
                                    <th class="px-2 py-1">Handle</th>
                                    <th class="px-2 py-1">Vendor</th>
                                    <th class="px-2 py-1">SKU</th>
                                    <th class="px-2 py-1">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="text-slate-800">
                                <tr v-for="(r, idx) in importResult!.unmatched_rows" :key="idx" class="border-t border-slate-200">
                                    <td class="px-2 py-1">{{ r.handle }}</td>
                                    <td class="px-2 py-1">{{ r.vendor }}</td>
                                    <td class="px-2 py-1">{{ r.sku }}</td>
                                    <td class="px-2 py-1">{{ r.reason }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="importResult!.ambiguous_rows.length > 0" class="mt-4">
                    <h3 class="text-xs font-semibold text-slate-900">Ambiguous rows</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="text-slate-600">
                                <tr>
                                    <th class="px-2 py-1">Handle</th>
                                    <th class="px-2 py-1">Vendor</th>
                                    <th class="px-2 py-1">SKU</th>
                                    <th class="px-2 py-1">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="text-slate-800">
                                <tr v-for="(r, idx) in importResult!.ambiguous_rows" :key="idx" class="border-t border-slate-200">
                                    <td class="px-2 py-1">{{ r.handle }}</td>
                                    <td class="px-2 py-1">{{ r.vendor }}</td>
                                    <td class="px-2 py-1">{{ r.sku }}</td>
                                    <td class="px-2 py-1">{{ r.reason }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
                            <th class="px-2 py-2">Created</th>
                            <th class="px-2 py-2">ID</th>
                            <th class="px-2 py-2 text-right">Rows</th>
                            <th class="px-2 py-2 text-right">Applied</th>
                            <th class="px-2 py-2 text-right">Unmatched</th>
                            <th class="px-2 py-2 text-right">Ambiguous</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-800">
                        <tr v-for="c in checks" :key="c.id" class="border-t border-slate-200 hover:bg-slate-50">
                            <td class="px-2 py-2 text-slate-600">{{ formatTorontoDateTime(c.created_at) }}</td>
                            <td class="px-2 py-2">
                                <a class="underline underline-offset-2" :href="`/inventory-check/${c.id}`">{{ c.id }}</a>
                            </td>
                            <td class="px-2 py-2 text-right">{{ c.counts.items }}</td>
                            <td class="px-2 py-2 text-right">{{ c.counts.applied }}</td>
                            <td class="px-2 py-2 text-right">{{ c.counts.unmatched }}</td>
                            <td class="px-2 py-2 text-right">{{ c.counts.ambiguous }}</td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="meta && meta.total === 0" class="mt-3 text-sm text-slate-600">No inventory checks yet.</p>
            </div>
        </section>
    </main>
</template>





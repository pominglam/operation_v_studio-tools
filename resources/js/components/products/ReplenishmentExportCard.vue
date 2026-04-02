<script setup lang="ts">
import { ref } from 'vue';
import { api } from '../../lib/api';

type ReplenishmentRow = {
    sku: string;
    product_name: string;
    barcode: string | null;
    available_qty: number;
    maintain_qty: number;
    inbound_open_po_qty: number;
    suggested_order_qty: number;
};

const loading = ref(false);
const error = ref<string | null>(null);
const rows = ref<ReplenishmentRow[]>([]);
const totalSuggested = ref(0);

async function loadPreview(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{
            ok: boolean;
            data: ReplenishmentRow[];
            meta?: { total_suggested_order_qty?: number };
        }>('/api/v1/products/replenishment/preview');
        rows.value = Array.isArray(res.data.data) ? res.data.data : [];
        totalSuggested.value = Number(res.data.meta?.total_suggested_order_qty ?? 0) || 0;
    } catch (e: unknown) {
        const anyErr = e as any;
        const status = Number(anyErr?.response?.status ?? 0) || null;
        const msg = String(anyErr?.response?.data?.message ?? anyErr?.message ?? '').trim();
        error.value = status ? `Failed to load replenishment preview (HTTP ${status}). ${msg}` : 'Failed to load replenishment preview.';
    } finally {
        loading.value = false;
    }
}

function exportCsv(): void {
    window.location.assign('/api/v1/products/replenishment/export');
}
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="text-sm font-semibold text-slate-900">What to order</div>
                <div class="mt-1 text-sm text-slate-600">
                    Suggested order qty = max(Maintain - Available - Not arrived, 0).
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="loading"
                    @click="loadPreview"
                    data-testid="replenishment-preview-button"
                >
                    {{ loading ? 'Loading…' : 'Preview' }}
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                    @click="exportCsv"
                    data-testid="replenishment-export-button"
                >
                    Export reorder CSV
                </button>
            </div>
        </div>

        <div v-if="error" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
            {{ error }}
        </div>

        <div class="mt-3 text-sm text-slate-700">
            {{ rows.length }} row(s) · total suggested qty:
            <span class="font-semibold text-slate-900">{{ totalSuggested }}</span>
        </div>

        <div v-if="rows.length > 0" class="mt-3 overflow-x-auto rounded-md border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <th class="px-3 py-2">SKU</th>
                        <th class="px-3 py-2">Product Name</th>
                        <th class="px-3 py-2">Barcode</th>
                        <th class="px-3 py-2 text-right">Available</th>
                        <th class="px-3 py-2 text-right">Maintain</th>
                        <th class="px-3 py-2 text-right">Not arrived</th>
                        <th class="px-3 py-2 text-right">Reorder</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in rows" :key="r.sku" class="hover:bg-slate-50">
                        <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ r.sku }}</td>
                        <td class="px-3 py-2 text-slate-900">{{ r.product_name }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ r.barcode || '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ r.available_qty }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ r.maintain_qty }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ r.inbound_open_po_qty }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-semibold text-slate-900">{{ r.suggested_order_qty }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>


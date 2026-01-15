<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import { formatMoney2 } from '../../lib/money';

type PoLine = {
    purchase_order_uuid: string;
    vendor: string;
    ordered_date: string | null;
    shipped_date: string | null;
    received_date: string | null;
    unit_cost: string | null;
    ship_per_unit: string;
    surcharge_per_unit: string;
    landed_unit_cost: string | null;
};

const props = defineProps<{
    open: boolean;
    productId: string | null;
    productSku: string | null;
    productName: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const loading = ref(false);
const error = ref<string | null>(null);
const lines = ref<PoLine[]>([]);

const title = computed<string>(() => {
    const sku = props.productSku ?? '—';
    const name = props.productName ?? '';
    return name ? `${sku} — PO Lines` : `${sku} — PO Lines`;
});

async function load(): Promise<void> {
    if (!props.productId) return;
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{ lines: PoLine[] }>(`/api/v1/products/${props.productId}/po-lines`, {
            params: { limit: 50 },
        });
        lines.value = res.data.lines ?? [];
    } catch {
        error.value = 'Failed to load PO lines.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.productId] as const,
    ([open]) => {
        if (!open) return;
        void load();
    },
    { immediate: true },
);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-stretch justify-end bg-slate-900/40"
            role="dialog"
            aria-modal="true"
            @click.self="emit('close')"
        >
            <div class="h-full w-full max-w-4xl bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ title }}</div>
                        <div v-if="productName" class="mt-1 text-sm text-slate-600">{{ productName }}</div>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100"
                        @click="emit('close')"
                    >
                        Close
                    </button>
                </div>

                <div class="p-4">
                    <div
                        v-if="error"
                        class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                    >
                        {{ error }}
                    </div>

                    <div v-if="loading" class="text-sm text-slate-600">Loading…</div>

                    <div v-else class="overflow-hidden rounded-lg border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">PO</th>
                                    <th class="px-3 py-2 text-left">Vendor</th>
                                    <th class="px-3 py-2 text-left">Ordered</th>
                                    <th class="px-3 py-2 text-left">Received</th>
                                    <th class="px-3 py-2 text-right">Unit</th>
                                    <th class="px-3 py-2 text-right">Ship/unit</th>
                                    <th class="px-3 py-2 text-right">Surcharge/unit</th>
                                    <th class="px-3 py-2 text-right">Landed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="l in lines"
                                    :key="l.purchase_order_uuid"
                                    class="border-t border-slate-200"
                                >
                                    <td class="px-3 py-2">
                                        <RouterLink
                                            class="text-sky-700 hover:underline"
                                            :to="`/purchase-orders/${l.purchase_order_uuid}`"
                                        >
                                            {{ l.purchase_order_uuid.slice(0, 8) }}
                                        </RouterLink>
                                    </td>
                                    <td class="px-3 py-2">{{ l.vendor }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ l.ordered_date ?? '—' }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ l.received_date ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">
                                        {{ l.unit_cost ? formatMoney2(l.unit_cost) : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">
                                        {{ formatMoney2(l.ship_per_unit) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">
                                        {{ formatMoney2(l.surcharge_per_unit) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">
                                        {{ l.landed_unit_cost ? formatMoney2(l.landed_unit_cost) : '—' }}
                                    </td>
                                </tr>

                                <tr v-if="lines.length === 0">
                                    <td class="px-3 py-4 text-sm text-slate-600" colspan="8">
                                        No purchase order lines found for this product.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>


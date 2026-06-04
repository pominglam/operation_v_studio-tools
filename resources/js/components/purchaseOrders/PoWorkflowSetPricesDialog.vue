<script setup lang="ts">
export type PoSetPricePreviewRow = {
    product_uuid: string;
    sku: string;
    description: string;
    is_new_on_po: boolean;
    landed_unit_cost: string | null;
    current_price: string | null;
    current_multiplier: string | null;
    proposed_price: string | null;
    proposed_multiplier: string | null;
    keep_reason?: 'current_higher_than_formula' | null;
};

export type PoSetPricePreview = {
    multiplier: string;
    new_prices: PoSetPricePreviewRow[];
    updates: PoSetPricePreviewRow[];
    unchanged: PoSetPricePreviewRow[];
    skipped_no_cost: PoSetPricePreviewRow[];
    apply_count: number;
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    preview: PoSetPricePreview | null;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm'): void;
}>();

function formatMoney(value: string | null | undefined): string {
    if (value == null || value.trim() === '') return '—';
    return `$${value}`;
}

function formatMultiplier(value: string | null | undefined): string {
    if (value == null || value.trim() === '') return '—';
    return `${value}x`;
}

function formatMultiplierChange(row: PoSetPricePreviewRow): string {
    const current = formatMultiplier(row.current_multiplier);
    const proposed = formatMultiplier(row.proposed_multiplier);
    if (current === '—') return proposed;
    if (proposed === '—') return current;
    if (current === proposed) return current;
    return `${current} → ${proposed}`;
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="emit('cancel')"
        >
            <div
                class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-lg bg-white shadow-xl"
            >
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">Set/review selling prices</div>
                    <div v-if="preview" class="mt-1 text-xs text-slate-600">
                        Formula: landed cost × {{ preview.multiplier }}, rounded to X.99 CAD.
                        <span class="font-semibold text-slate-900">{{ preview.apply_count }}</span>
                        product(s) will change if you apply.
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="error" class="mb-3 text-sm text-rose-700">{{ error }}</p>

                    <template v-if="preview">
                        <section v-if="preview.new_prices.length" class="mb-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-emerald-800">
                                New prices ({{ preview.new_prices.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="py-1 pr-2">SKU</th>
                                        <th class="py-1 pr-2">Product</th>
                                        <th class="py-1 pr-2">PO</th>
                                        <th class="py-1 pr-2">Landed</th>
                                        <th class="py-1 pr-2">Current</th>
                                        <th class="py-1 pr-2">Mult.</th>
                                        <th class="py-1">Proposed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in preview.new_prices"
                                        :key="row.product_uuid"
                                        class="border-t border-slate-100 text-slate-800"
                                    >
                                        <td class="py-1.5 pr-2 font-medium">{{ row.sku }}</td>
                                        <td
                                            class="max-w-[14rem] py-1.5 pr-2 text-slate-700"
                                            :title="row.description"
                                        >
                                            {{ row.description || '—' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ row.is_new_on_po ? 'New' : 'Existing' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ formatMoney(row.landed_unit_cost) }}
                                        </td>
                                        <td class="py-1.5 pr-2">—</td>
                                        <td class="py-1.5 pr-2 tabular-nums">
                                            {{ formatMultiplier(row.proposed_multiplier) }}
                                        </td>
                                        <td class="py-1.5 font-semibold text-emerald-800">
                                            {{ formatMoney(row.proposed_price) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section v-if="preview.updates.length" class="mb-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Price updates ({{ preview.updates.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="py-1 pr-2">SKU</th>
                                        <th class="py-1 pr-2">Product</th>
                                        <th class="py-1 pr-2">PO</th>
                                        <th class="py-1 pr-2">Landed</th>
                                        <th class="py-1 pr-2">Current</th>
                                        <th class="py-1 pr-2">Mult.</th>
                                        <th class="py-1">Proposed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in preview.updates"
                                        :key="row.product_uuid"
                                        class="border-t border-slate-100 text-slate-800"
                                    >
                                        <td class="py-1.5 pr-2 font-medium">{{ row.sku }}</td>
                                        <td
                                            class="max-w-[14rem] py-1.5 pr-2 text-slate-700"
                                            :title="row.description"
                                        >
                                            {{ row.description || '—' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ row.is_new_on_po ? 'New' : 'Existing' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ formatMoney(row.landed_unit_cost) }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ formatMoney(row.current_price) }}
                                        </td>
                                        <td class="py-1.5 pr-2 tabular-nums">
                                            {{ formatMultiplierChange(row) }}
                                        </td>
                                        <td class="py-1.5 font-semibold text-amber-900">
                                            {{ formatMoney(row.proposed_price) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section v-if="preview.unchanged.length" class="mb-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                                No price change ({{ preview.unchanged.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="py-1 pr-2">SKU</th>
                                        <th class="py-1 pr-2">Product</th>
                                        <th class="py-1 pr-2">PO</th>
                                        <th class="py-1 pr-2">Landed</th>
                                        <th class="py-1 pr-2">Current</th>
                                        <th class="py-1 pr-2">Mult.</th>
                                        <th class="py-1">Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in preview.unchanged"
                                        :key="row.product_uuid"
                                        class="border-t border-slate-100 text-slate-700"
                                    >
                                        <td class="py-1.5 pr-2 font-medium">{{ row.sku }}</td>
                                        <td
                                            class="max-w-[14rem] py-1.5 pr-2"
                                            :title="row.description"
                                        >
                                            {{ row.description || '—' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ row.is_new_on_po ? 'New' : 'Existing' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ formatMoney(row.landed_unit_cost) }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ formatMoney(row.current_price) }}
                                        </td>
                                        <td class="py-1.5 pr-2 tabular-nums">
                                            {{ formatMultiplier(row.current_multiplier) }}
                                        </td>
                                        <td class="py-1.5">
                                            <span
                                                v-if="
                                                    row.keep_reason === 'current_higher_than_formula'
                                                "
                                                class="text-slate-600"
                                            >
                                                Keeping current (formula
                                                {{ formatMoney(row.proposed_price) }})
                                            </span>
                                            <span v-else class="text-slate-500">Already set</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section v-if="preview.skipped_no_cost.length">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Missing landed cost — cannot price ({{
                                    preview.skipped_no_cost.length
                                }})
                            </div>
                            <ul class="mt-2 space-y-1 text-xs text-slate-600">
                                <li v-for="row in preview.skipped_no_cost" :key="row.product_uuid">
                                    <span class="font-medium text-slate-800">{{ row.sku }}</span>
                                    <span v-if="row.description"> — {{ row.description }}</span>
                                    <span v-if="row.is_new_on_po" class="text-slate-500">(New on PO)</span>
                                </li>
                            </ul>
                        </section>

                        <p
                            v-if="
                                preview.new_prices.length === 0 &&
                                preview.updates.length === 0 &&
                                preview.unchanged.length === 0 &&
                                preview.skipped_no_cost.length === 0
                            "
                            class="text-sm text-slate-600"
                        >
                            No products on this PO.
                        </p>
                    </template>

                    <p v-else-if="!error" class="text-sm text-slate-600">Loading preview…</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busy || !preview || preview.apply_count === 0"
                        @click="emit('confirm')"
                    >
                        {{ busy ? 'Applying…' : 'Apply prices' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

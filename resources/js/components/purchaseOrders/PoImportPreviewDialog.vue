<script setup lang="ts">
import { formatMoney2, formatMoney2OrOriginal } from '../../lib/money';

export type PoImportPreviewLine = {
    item: string | null;
    sku: string;
    qty: number;
    unit_price_hkd: string | null;
    line_total_hkd: string | null;
    unit_price_cad: string | null;
    line_total_cad: string | null;
};

export type PoImportPreview = {
    format: string;
    vendor: string;
    vendor_currency_code: string;
    vendor_product_total_hkd: string | null;
    vendor_freight_total_hkd: string | null;
    product_total_cad: string | null;
    shipping_total_cad: string | null;
    total_paid_cad: string | null;
    product_total_includes_fees: boolean;
    fx_rate_to_cad: string | null;
    lines: PoImportPreviewLine[];
    totals: {
        qty: number;
        line_total_hkd: string | null;
        line_total_cad: string | null;
    };
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    preview: PoImportPreview | null;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm'): void;
}>();

function formatMoney(value: string | null | undefined): string {
    return formatMoney2(value);
}

function formatFx(value: string | null | undefined): string {
    return formatMoney2OrOriginal(value);
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
                class="flex max-h-[85vh] w-full max-w-6xl flex-col rounded-lg bg-white shadow-xl"
            >
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">Import preview</div>
                    <div v-if="preview" class="mt-1 text-xs text-slate-600">
                        Format:
                        <span class="font-semibold text-slate-900">{{ preview.format }}</span>
                        · {{ preview.lines.length }} line(s) · Σ qty
                        <span class="font-semibold text-slate-900">{{ preview.totals.qty }}</span>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="error" class="mb-3 text-sm text-rose-700">{{ error }}</p>

                    <template v-if="preview">
                        <div
                            class="mb-3 grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700 sm:grid-cols-4"
                        >
                            <div v-if="preview.product_total_includes_fees && preview.total_paid_cad">
                                <div class="text-slate-500">Total paid (CAD)</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney(preview.total_paid_cad) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">Product (CAD)</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney(preview.product_total_cad) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">Shipping (CAD)</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney(preview.shipping_total_cad) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">Product (HKD)</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney(preview.vendor_product_total_hkd) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">Freight (HKD)</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney(preview.vendor_freight_total_hkd) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">Implied FX (HKD→CAD)</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatFx(preview.fx_rate_to_cad) }}
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="preview.product_total_includes_fees"
                            class="mb-3 text-xs text-slate-600"
                        >
                            Product and shipping CAD were split from your total paid amount using
                            the invoice HKD product vs freight ratio.
                        </p>

                        <table class="w-full text-left text-xs">
                            <thead class="text-slate-500">
                                <tr>
                                    <th class="py-1 pr-2">Item</th>
                                    <th class="py-1 pr-2">SKU</th>
                                    <th class="py-1 pr-2 text-right">Qty</th>
                                    <th class="py-1 pr-2 text-right">Unit (HKD)</th>
                                    <th class="py-1 pr-2 text-right">Line (HKD)</th>
                                    <th class="py-1 pr-2 text-right">Unit (CAD)</th>
                                    <th class="py-1 text-right">Line (CAD)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, idx) in preview.lines"
                                    :key="`${row.sku}-${idx}`"
                                    class="border-t border-slate-100 text-slate-800"
                                >
                                    <td
                                        class="max-w-[14rem] py-1.5 pr-2 text-slate-700"
                                        :title="row.item ?? undefined"
                                    >
                                        {{ row.item || '—' }}
                                    </td>
                                    <td class="py-1.5 pr-2 font-medium">{{ row.sku }}</td>
                                    <td class="py-1.5 pr-2 text-right">{{ row.qty }}</td>
                                    <td class="py-1.5 pr-2 text-right">
                                        {{ formatMoney(row.unit_price_hkd) }}
                                    </td>
                                    <td class="py-1.5 pr-2 text-right">
                                        {{ formatMoney(row.line_total_hkd) }}
                                    </td>
                                    <td class="py-1.5 pr-2 text-right">
                                        {{ formatMoney(row.unit_price_cad) }}
                                    </td>
                                    <td class="py-1.5 text-right">
                                        {{ formatMoney(row.line_total_cad) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-slate-200 text-slate-900">
                                <tr>
                                    <td colspan="2" class="py-2 pr-2 font-semibold">Totals</td>
                                    <td class="py-2 pr-2 text-right font-semibold">
                                        {{ preview.totals.qty }}
                                    </td>
                                    <td class="py-2 pr-2" />
                                    <td class="py-2 pr-2 text-right font-semibold">
                                        {{ formatMoney(preview.totals.line_total_hkd) }}
                                    </td>
                                    <td class="py-2 pr-2" />
                                    <td class="py-2 text-right font-semibold">
                                        {{ formatMoney(preview.totals.line_total_cad) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
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
                        :disabled="busy || !preview || preview.lines.length === 0"
                        @click="emit('confirm')"
                    >
                        {{ busy ? 'Importing…' : 'Confirm import' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

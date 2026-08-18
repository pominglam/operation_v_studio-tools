<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import { formatMoney2 } from '../../lib/money';

export type PoCombinedPaymentAllocation = {
    purchase_order_id: string;
    vendor: string;
    supplier_order_id: string | null;
    shipment_method: 'air' | 'sea' | null;
    vendor_product_total: string;
    vendor_shipping_total: string | null;
    product_total_cad: string;
    shipping_total_cad: string | null;
    fx_rate_to_cad: string;
};

export type PoCombinedPaymentPreview = {
    id: string | null;
    vendor_currency_code: string;
    vendor_total: string;
    total_paid_cad: string;
    fx_rate_to_cad: string;
    includes_shipping: boolean;
    allocations: PoCombinedPaymentAllocation[];
};

export type PoCombinedPaymentValues = {
    total_paid_cad: string;
    includes_shipping: boolean;
    product_paid_cad?: string;
    shipping_paid_cad?: string;
    allocations?: Array<{
        purchase_order_id: string;
        product_total_cad: string;
        shipping_total_cad: string | null;
    }>;
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    selectedCount: number;
    preview: PoCombinedPaymentPreview | null;
    error: string | null;
}>();

const emit = defineEmits<{
    (event: 'cancel'): void;
    (event: 'preview', values: PoCombinedPaymentValues): void;
    (event: 'confirm', values: PoCombinedPaymentValues): void;
}>();

const totalPaidCad = ref(props.preview?.total_paid_cad ?? '');
const includesShipping = ref(props.preview?.includes_shipping ?? false);
const amountMode = ref<'total' | 'split'>('total');
const productPaidCad = ref('');
const shippingPaidCad = ref('');
const manualAllocation = ref(false);
const allocationDrafts = ref(buildAllocationDrafts(props.preview));

const splitTotalCents = computed(() => {
    const product = moneyToCents(productPaidCad.value);
    const shipping = moneyToCents(shippingPaidCad.value);
    if (product === null || shipping === null) return null;
    return product + shipping;
});
const effectiveTotalPaidCad = computed(() =>
    amountMode.value === 'split'
        ? splitTotalCents.value === null
            ? ''
            : centsLabel(splitTotalCents.value)
        : totalPaidCad.value.trim(),
);
const values = computed<PoCombinedPaymentValues>(() => {
    const base = {
        total_paid_cad: effectiveTotalPaidCad.value,
        includes_shipping: includesShipping.value,
        ...(amountMode.value === 'split'
            ? {
                  product_paid_cad: productPaidCad.value.trim(),
                  shipping_paid_cad: shippingPaidCad.value.trim(),
              }
            : {}),
    };
    if (!manualAllocation.value || !props.preview) return base;

    return {
        ...base,
        allocations: props.preview.allocations.map((row) => ({
            purchase_order_id: row.purchase_order_id,
            product_total_cad:
                allocationDrafts.value[row.purchase_order_id].product_total_cad.trim(),
            shipping_total_cad: includesShipping.value
                ? allocationDrafts.value[row.purchase_order_id].shipping_total_cad.trim()
                : null,
        })),
    };
});
const validTotal = computed(() => {
    if (amountMode.value === 'split') {
        const product = moneyToCents(productPaidCad.value);
        const shipping = moneyToCents(shippingPaidCad.value);
        return product !== null && product > 0 && shipping !== null;
    }
    return (
        Number.isFinite(Number(values.value.total_paid_cad)) &&
        Number(values.value.total_paid_cad) > 0
    );
});
const previewMatchesValues = computed(
    () =>
        props.preview !== null &&
        Number(props.preview.total_paid_cad) === Number(values.value.total_paid_cad) &&
        props.preview.includes_shipping === values.value.includes_shipping &&
        (!manualAllocation.value || manualAllocationValid.value),
);
const manualAllocatedProductCents = computed(() => {
    if (!props.preview) return 0;
    return props.preview.allocations.reduce(
        (total, row) =>
            total +
            (moneyToCents(allocationDrafts.value[row.purchase_order_id]?.product_total_cad ?? '') ??
                0),
        0,
    );
});
const manualAllocatedShippingCents = computed(() => {
    if (!props.preview || !includesShipping.value) return 0;
    return props.preview.allocations.reduce((total, row) => {
        const draft = allocationDrafts.value[row.purchase_order_id];
        return total + (moneyToCents(draft?.shipping_total_cad ?? '') ?? 0);
    }, 0);
});
const manualAllocatedCents = computed(
    () => manualAllocatedProductCents.value + manualAllocatedShippingCents.value,
);
const manualRemainingCents = computed(
    () => (moneyToCents(values.value.total_paid_cad) ?? 0) - manualAllocatedCents.value,
);
const manualProductRemainingCents = computed(
    () => (moneyToCents(productPaidCad.value) ?? 0) - manualAllocatedProductCents.value,
);
const manualShippingRemainingCents = computed(
    () => (moneyToCents(shippingPaidCad.value) ?? 0) - manualAllocatedShippingCents.value,
);
const manualAllocationValid = computed(() => {
    if (!manualAllocation.value || !props.preview) return true;
    const rowsAreValid = props.preview.allocations.every((row) => {
        const draft = allocationDrafts.value[row.purchase_order_id];
        const product = moneyToCents(draft?.product_total_cad ?? '');
        const shipping = moneyToCents(draft?.shipping_total_cad ?? '');
        return product !== null && product > 0 && (!includesShipping.value || shipping !== null);
    });
    const splitMatches =
        amountMode.value !== 'split' ||
        (manualProductRemainingCents.value === 0 && manualShippingRemainingCents.value === 0);
    return rowsAreValid && manualRemainingCents.value === 0 && splitMatches;
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        totalPaidCad.value = '';
        includesShipping.value = false;
        amountMode.value = 'total';
        productPaidCad.value = '';
        shippingPaidCad.value = '';
        manualAllocation.value = false;
        allocationDrafts.value = {};
    },
);

watch(amountMode, (mode) => {
    if (mode === 'split') {
        includesShipping.value = true;
    }
});

watch(
    () => props.preview,
    (preview) => {
        if (!preview) return;
        totalPaidCad.value = preview.total_paid_cad;
        includesShipping.value = preview.includes_shipping;
        allocationDrafts.value = buildAllocationDrafts(preview);
    },
);

function shipmentLabel(method: PoCombinedPaymentAllocation['shipment_method']): string {
    if (method === 'air') return 'Air';
    if (method === 'sea') return 'Sea';
    return '—';
}

function buildAllocationDrafts(
    preview: PoCombinedPaymentPreview | null,
): Record<string, { product_total_cad: string; shipping_total_cad: string }> {
    if (!preview) return {};
    return Object.fromEntries(
        preview.allocations.map((row) => [
            row.purchase_order_id,
            {
                product_total_cad: row.product_total_cad,
                shipping_total_cad: row.shipping_total_cad ?? '',
            },
        ]),
    );
}

function moneyToCents(value: string): number | null {
    const normalized = value.trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) return null;
    return Math.round(Number(normalized) * 100);
}

function centsLabel(cents: number): string {
    return (cents / 100).toFixed(2);
}

function rowProductCad(row: PoCombinedPaymentAllocation): string {
    return manualAllocation.value
        ? allocationDrafts.value[row.purchase_order_id].product_total_cad
        : row.product_total_cad;
}

function rowShippingCad(row: PoCombinedPaymentAllocation): string {
    return manualAllocation.value
        ? allocationDrafts.value[row.purchase_order_id].shipping_total_cad
        : (row.shipping_total_cad ?? '0');
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="combined-payment-title"
            @click.self="emit('cancel')"
        >
            <div class="flex max-h-[85vh] w-full max-w-5xl flex-col rounded-lg bg-white shadow-xl">
                <div class="border-b border-slate-200 px-4 py-3">
                    <div id="combined-payment-title" class="text-sm font-semibold text-slate-900">
                        Record combined payment
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        Allocate one CAD payment across {{ selectedCount }} selected
                        foreign-currency POs. Each PO remains a separate shipment.
                    </p>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                    <fieldset class="mb-3">
                        <legend class="text-xs font-medium text-slate-700">Amount available</legend>
                        <div class="mt-1 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-700">
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="amountMode"
                                    data-testid="combined-payment-amount-mode-total"
                                    type="radio"
                                    value="total"
                                    class="border-slate-300"
                                />
                                Total paid only
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="amountMode"
                                    data-testid="combined-payment-amount-mode-split"
                                    type="radio"
                                    value="split"
                                    class="border-slate-300"
                                />
                                Product + shipping split
                            </label>
                        </div>
                    </fieldset>

                    <div v-if="amountMode === 'total'" class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-xs font-medium text-slate-700">
                            Total paid (CAD)
                            <input
                                v-model="totalPaidCad"
                                data-testid="combined-payment-total"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                        </label>
                        <label class="flex items-start gap-2 self-end text-xs text-slate-700">
                            <input
                                v-model="includesShipping"
                                data-testid="combined-payment-includes-shipping"
                                type="checkbox"
                                class="mt-0.5 rounded border-slate-300"
                            />
                            <span>
                                Payment includes shipping. Split product and shipping using each
                                PO’s vendor-currency invoice totals.
                            </span>
                        </label>
                    </div>
                    <div v-else class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-xs font-medium text-slate-700">
                            Combined product cost (CAD)
                            <input
                                v-model="productPaidCad"
                                data-testid="combined-payment-product-paid"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                        </label>
                        <label class="block text-xs font-medium text-slate-700">
                            Combined shipping cost (CAD)
                            <input
                                v-model="shippingPaidCad"
                                data-testid="combined-payment-shipping-paid"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                        </label>
                        <div class="text-xs text-slate-600 sm:col-span-2">
                            Calculated total:
                            <span class="font-semibold text-slate-900">
                                {{ splitTotalCents === null ? '—' : centsLabel(splitTotalCents) }}
                                CAD
                            </span>
                        </div>
                    </div>

                    <p v-if="amountMode === 'total'" class="mt-3 text-xs text-slate-600">
                        Surcharges are not included. When shipping is unchecked, each PO’s existing
                        CAD shipping total stays unchanged.
                    </p>
                    <p v-else class="mt-3 text-xs text-slate-600">
                        Surcharges are not included. Product and shipping amounts are allocated
                        separately across the selected POs.
                    </p>
                    <p v-if="error" class="mt-3 text-sm text-rose-700">{{ error }}</p>

                    <template v-if="preview">
                        <div
                            class="mt-4 grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs sm:grid-cols-4"
                        >
                            <div>
                                <div class="text-slate-500">Vendor total</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney2(preview.vendor_total) }}
                                    {{ preview.vendor_currency_code }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">Paid</div>
                                <div class="font-semibold text-slate-900">
                                    {{ formatMoney2(preview.total_paid_cad) }} CAD
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">
                                    {{ preview.vendor_currency_code }}→CAD
                                </div>
                                <div class="font-semibold text-slate-900">
                                    {{ preview.fx_rate_to_cad }}
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-500">POs</div>
                                <div class="font-semibold text-slate-900">
                                    {{ preview.allocations.length }}
                                </div>
                            </div>
                        </div>

                        <label
                            class="mt-4 flex items-start gap-2 rounded-md border border-slate-200 p-3 text-xs text-slate-700"
                        >
                            <input
                                v-model="manualAllocation"
                                data-testid="combined-payment-manual-allocation"
                                type="checkbox"
                                class="mt-0.5 rounded border-slate-300"
                            />
                            <span>
                                <span class="font-medium text-slate-900"
                                    >Enter exact CAD amounts manually</span
                                >
                                <span class="mt-0.5 block">
                                    Use this when the invoice or payment record already gives you
                                    the product and shipping split for each PO.
                                </span>
                            </span>
                        </label>

                        <div
                            v-if="manualAllocation"
                            class="mt-3 flex flex-wrap justify-end gap-x-5 gap-y-1 text-xs"
                        >
                            <span class="text-slate-600">
                                Allocated: {{ centsLabel(manualAllocatedCents) }} CAD
                            </span>
                            <span
                                :class="
                                    manualRemainingCents === 0
                                        ? 'font-medium text-emerald-700'
                                        : 'font-medium text-rose-700'
                                "
                            >
                                Remaining: {{ centsLabel(manualRemainingCents) }} CAD
                            </span>
                            <template v-if="amountMode === 'split'">
                                <span
                                    :class="
                                        manualProductRemainingCents === 0
                                            ? 'font-medium text-emerald-700'
                                            : 'font-medium text-rose-700'
                                    "
                                >
                                    Product remaining:
                                    {{ centsLabel(manualProductRemainingCents) }} CAD
                                </span>
                                <span
                                    :class="
                                        manualShippingRemainingCents === 0
                                            ? 'font-medium text-emerald-700'
                                            : 'font-medium text-rose-700'
                                    "
                                >
                                    Shipping remaining:
                                    {{ centsLabel(manualShippingRemainingCents) }} CAD
                                </span>
                            </template>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-left text-xs">
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="px-2 py-2">PO / Shipment</th>
                                        <th class="px-2 py-2 text-right">Product (vendor)</th>
                                        <th class="px-2 py-2 text-right">Shipping (vendor)</th>
                                        <th class="px-2 py-2 text-right">Product (CAD)</th>
                                        <th class="px-2 py-2 text-right">Shipping (CAD)</th>
                                        <th class="px-2 py-2 text-right">Shipment total (CAD)</th>
                                    </tr>
                                </thead>
                                <tbody class="text-slate-800">
                                    <tr
                                        v-for="row in preview.allocations"
                                        :key="row.purchase_order_id"
                                        class="border-t border-slate-200"
                                    >
                                        <td class="px-2 py-2">
                                            <a
                                                :href="`/purchase-orders/${row.purchase_order_id}`"
                                                class="font-medium underline underline-offset-2"
                                            >
                                                {{ row.supplier_order_id || row.purchase_order_id }}
                                            </a>
                                            <div class="text-slate-500">
                                                {{ shipmentLabel(row.shipment_method) }}
                                            </div>
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            {{ formatMoney2(row.vendor_product_total) }}
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            {{ formatMoney2(row.vendor_shipping_total) }}
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            <input
                                                v-if="manualAllocation"
                                                v-model="
                                                    allocationDrafts[row.purchase_order_id]
                                                        .product_total_cad
                                                "
                                                data-testid="combined-payment-product-cad"
                                                type="text"
                                                inputmode="decimal"
                                                aria-label="Product total CAD"
                                                class="w-24 rounded-md border border-slate-300 px-2 py-1 text-right"
                                            />
                                            <template v-else>
                                                {{ formatMoney2(row.product_total_cad) }}
                                            </template>
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            <input
                                                v-if="manualAllocation && includesShipping"
                                                v-model="
                                                    allocationDrafts[row.purchase_order_id]
                                                        .shipping_total_cad
                                                "
                                                data-testid="combined-payment-shipping-cad"
                                                type="text"
                                                inputmode="decimal"
                                                aria-label="Shipping total CAD"
                                                class="w-24 rounded-md border border-slate-300 px-2 py-1 text-right"
                                            />
                                            <template v-else>
                                                {{ formatMoney2(row.shipping_total_cad) }}
                                            </template>
                                        </td>
                                        <td class="px-2 py-2 text-right font-semibold">
                                            {{
                                                formatMoney2(
                                                    (
                                                        Number(rowProductCad(row)) +
                                                        Number(rowShippingCad(row))
                                                    ).toFixed(2),
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
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
                        data-testid="combined-payment-preview"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="
                            busy || !validTotal || (manualAllocation && !manualAllocationValid)
                        "
                        @click="emit('preview', values)"
                    >
                        {{ busy ? 'Working…' : 'Preview allocation' }}
                    </button>
                    <button
                        type="button"
                        data-testid="combined-payment-confirm"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busy || !previewMatchesValues"
                        @click="emit('confirm', values)"
                    >
                        {{ busy ? 'Recording…' : 'Record payment' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

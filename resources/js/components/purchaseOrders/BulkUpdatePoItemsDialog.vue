<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { parseNonNegativeIntOrNull } from '../../lib/numbers';

export type PoItemsBulkChanges = {
    qty_shipped?: number | null;
    qty_received?: number | null;
    set_shipped_to_ordered?: boolean;
    set_received_to_shipped?: boolean;
    product_vendor?: string | null;
};

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
    vendorOptions?: string[];
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', payload: { changes: PoItemsBulkChanges }): void;
}>();

type BulkFieldState<T> = {
    apply: boolean;
    value: T;
};

const localError = ref<string | null>(null);

const qtyShipped = ref<BulkFieldState<string>>({ apply: false, value: '' });
const qtyReceived = ref<BulkFieldState<string>>({ apply: false, value: '' });
const setShippedToOrdered = ref(false);
const setReceivedToShipped = ref(false);
const productVendor = ref<BulkFieldState<string>>({ apply: false, value: '' });

const hasAnyApply = computed<boolean>(() => {
    return (
        qtyShipped.value.apply ||
        qtyReceived.value.apply ||
        setShippedToOrdered.value ||
        setReceivedToShipped.value ||
        productVendor.value.apply
    );
});

function normalizeVendorOptions(list: string[] | undefined, current: string): string[] {
    const base = (list ?? []).map((v) => String(v).trim()).filter((v) => v !== '');
    const cur = current.trim();
    const merged = cur !== '' ? [...base, cur] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
}

const vendorChoices = computed<string[]>(() =>
    normalizeVendorOptions(props.vendorOptions, productVendor.value.value),
);

function reset(): void {
    localError.value = null;
    qtyShipped.value = { apply: false, value: '' };
    qtyReceived.value = { apply: false, value: '' };
    setShippedToOrdered.value = false;
    setReceivedToShipped.value = false;
    productVendor.value = { apply: false, value: '' };
}

watch(
    () => props.open,
    (next) => {
        if (next) reset();
    },
);

watch([qtyShipped, qtyReceived, setShippedToOrdered, setReceivedToShipped, productVendor], () => {
    if (localError.value !== null) localError.value = null;
});

function parseNullableInt(input: string): number | null {
    return parseNonNegativeIntOrNull(input);
}

function onConfirm(): void {
    localError.value = null;

    if (!hasAnyApply.value) {
        localError.value = 'Select at least one field to update.';
        return;
    }

    const changes: PoItemsBulkChanges = {};

    if (setShippedToOrdered.value) {
        changes.set_shipped_to_ordered = true;
    }

    if (setReceivedToShipped.value) {
        changes.set_received_to_shipped = true;
    }

    if (qtyShipped.value.apply) {
        changes.qty_shipped = parseNullableInt(qtyShipped.value.value);
    }

    if (qtyReceived.value.apply) {
        changes.qty_received = parseNullableInt(qtyReceived.value.value);
    }

    if (productVendor.value.apply) {
        const trimmed = productVendor.value.value.trim();
        changes.product_vendor = trimmed === '' ? null : trimmed;
    }

    emit('confirm', { changes });
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
            <div class="w-full max-w-2xl rounded-lg bg-white p-4 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Bulk update PO items</div>
                        <div class="mt-1 text-sm text-slate-600">
                            Update fields for
                            <span class="font-semibold text-slate-900">{{ selectedCount }}</span>
                            selected item(s).
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Close
                    </button>
                </div>

                <div
                    v-if="localError"
                    class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                >
                    {{ localError }}
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-6">
                    <div class="md:col-span-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="qtyShipped.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Qty shipped
                        </label>
                        <input
                            v-model="qtyShipped.value"
                            class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            inputmode="numeric"
                            :disabled="busy || !qtyShipped.apply"
                            placeholder="(blank clears)"
                        />
                    </div>

                    <div class="md:col-span-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="qtyReceived.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Qty received
                        </label>
                        <input
                            v-model="qtyReceived.value"
                            class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            inputmode="numeric"
                            :disabled="busy || !qtyReceived.apply"
                            placeholder="(blank clears)"
                        />
                        <p class="mt-1 text-xs text-slate-500">Blocked if inventory lots already exist for any selected item.</p>
                    </div>

                    <div class="md:col-span-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="setShippedToOrdered" type="checkbox" class="h-4 w-4 rounded" />
                            Set shipped = ordered
                        </label>
                    </div>
                    <div class="md:col-span-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="setReceivedToShipped" type="checkbox" class="h-4 w-4 rounded" />
                            Set received = shipped
                        </label>
                    </div>

                    <div class="md:col-span-6">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="productVendor.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Product vendor
                        </label>
                        <input
                            v-model="productVendor.value"
                            class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            list="po-bulk-vendor-options"
                            :disabled="busy || !productVendor.apply"
                            placeholder="Stedi, Dspiae, …"
                        />
                        <datalist id="po-bulk-vendor-options">
                            <option v-for="v in vendorChoices" :key="v" :value="v" />
                        </datalist>
                        <p class="mt-1 text-xs text-slate-500">
                            Sets catalog product vendor for each selected line. Type a new name to add a vendor on the
                            fly.
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                        @click="onConfirm"
                    >
                        {{ busy ? 'Updating…' : 'Update selected' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>


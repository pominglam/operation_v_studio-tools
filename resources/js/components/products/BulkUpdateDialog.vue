<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { BulkUpdateProductChanges } from './ProductsTable.vue';

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', payload: { changes: BulkUpdateProductChanges; renamePlamodAssets: boolean }): void;
}>();

type BulkFieldState<T> = {
    apply: boolean;
    value: T;
};

const localError = ref<string | null>(null);

const sku = ref<BulkFieldState<string>>({ apply: false, value: '' });
const barcode = ref<BulkFieldState<string>>({ apply: false, value: '' });
const description = ref<BulkFieldState<string>>({ apply: false, value: '' });
const handle = ref<BulkFieldState<string>>({ apply: false, value: '' });
const type = ref<BulkFieldState<string>>({ apply: false, value: '' });
const vendor = ref<BulkFieldState<string>>({ apply: false, value: '' });
const publishedOnShopify = ref<BulkFieldState<'true' | 'false'>>({ apply: false, value: 'false' });
const extended = ref<BulkFieldState<string>>({ apply: false, value: '' });
const renamePlamodAssets = ref(false);

const hasAnyApply = computed<boolean>(() => {
    return (
        sku.value.apply ||
        barcode.value.apply ||
        description.value.apply ||
        handle.value.apply ||
        type.value.apply ||
        vendor.value.apply ||
        publishedOnShopify.value.apply ||
        extended.value.apply ||
        renamePlamodAssets.value
    );
});

function reset(): void {
    localError.value = null;
    sku.value = { apply: false, value: '' };
    barcode.value = { apply: false, value: '' };
    description.value = { apply: false, value: '' };
    handle.value = { apply: false, value: '' };
    type.value = { apply: false, value: '' };
    vendor.value = { apply: false, value: '' };
    publishedOnShopify.value = { apply: false, value: 'false' };
    extended.value = { apply: false, value: '' };
    renamePlamodAssets.value = false;
}

watch(
    () => props.open,
    (next) => {
        if (next) reset();
    },
);

watch(
    [sku, barcode, description, handle, type, vendor, publishedOnShopify, extended],
    () => {
        // Clear stale validation messages as the user edits fields
        if (localError.value !== null) {
            localError.value = null;
        }
    },
    { deep: true },
);

function parseNullableNumericString(input: string): string | null {
    const trimmed = input.trim();
    if (trimmed === '') return null;
    const n = Number(trimmed);
    if (!Number.isFinite(n)) {
        throw new Error('invalid');
    }
    return trimmed;
}

function onConfirm(): void {
    localError.value = null;

    if (!hasAnyApply.value) {
        localError.value = 'Select at least one field to update.';
        return;
    }

    const changes: BulkUpdateProductChanges = {};

    if (sku.value.apply) {
        const nextSku = sku.value.value.trim();
        if (nextSku === '') {
            localError.value = 'SKU is required when updating SKU.';
            return;
        }
        changes.sku = nextSku;
    }

    if (barcode.value.apply) {
        const nextBarcode = barcode.value.value.trim();
        changes.barcode = nextBarcode === '' ? null : nextBarcode;
    }

    if (description.value.apply) {
        const nextDescription = description.value.value.trim();
        if (nextDescription === '') {
            localError.value = 'Name is required when updating Name.';
            return;
        }
        changes.description = nextDescription;
    }

    if (handle.value.apply) {
        const nextHandle = handle.value.value.trim();
        changes.handle = nextHandle === '' ? null : nextHandle;
    }

    if (type.value.apply) {
        const nextType = type.value.value.trim();
        changes.type = nextType === '' ? null : nextType;
    }

    if (vendor.value.apply) {
        const nextVendor = vendor.value.value.trim();
        changes.vendor = nextVendor === '' ? null : nextVendor;
    }

    if (publishedOnShopify.value.apply) {
        changes.published_on_shopify = publishedOnShopify.value.value === 'true';
    }

    if (extended.value.apply) {
        try {
            changes.extended = parseNullableNumericString(extended.value.value);
        } catch {
            localError.value = 'Total cost must be a number.';
            return;
        }
    }

    emit('confirm', { changes, renamePlamodAssets: renamePlamodAssets.value });
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
            <div class="w-full max-w-3xl rounded-lg bg-white p-4 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">Bulk update products</div>
                        <div class="mt-1 text-sm text-slate-600">
                            Update fields for
                            <span class="font-semibold text-slate-900">{{ selectedCount }}</span>
                            selected product(s). Leave a field blank to clear it (where allowed).
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

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-6">
                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="sku.apply" type="checkbox" class="h-4 w-4 rounded" />
                            SKU
                        </label>
                        <input
                            v-model="sku.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            :disabled="!sku.apply || busy"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="barcode.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Barcode
                        </label>
                        <input
                            v-model="barcode.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            :disabled="!barcode.apply || busy"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="vendor.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Vendor
                        </label>
                        <input
                            v-model="vendor.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            :disabled="!vendor.apply || busy"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="publishedOnShopify.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                            />
                            Published on Shopify
                        </label>
                        <select
                            v-model="publishedOnShopify.value"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            :disabled="!publishedOnShopify.apply || busy"
                        >
                            <option value="true">True</option>
                            <option value="false">False</option>
                        </select>
                    </div>

                    <div class="md:col-span-6">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="description.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                            />
                            Name
                        </label>
                        <input
                            v-model="description.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            :disabled="!description.apply || busy"
                        />
                    </div>

                    <div class="md:col-span-6">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="handle.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Handle
                        </label>
                        <input
                            v-model="handle.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            :disabled="!handle.apply || busy"
                            placeholder="(blank clears)"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input v-model="type.apply" type="checkbox" class="h-4 w-4 rounded" />
                            Type
                        </label>
                        <input
                            v-model="type.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            :disabled="!type.apply || busy"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="extended.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                            />
                            Total cost
                        </label>
                        <input
                            v-model="extended.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            inputmode="decimal"
                            :disabled="!extended.apply || busy"
                        />
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">Bulk actions</div>
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-800">
                        <input v-model="renamePlamodAssets" type="checkbox" class="h-4 w-4 rounded" :disabled="busy" />
                        Rename Plamod image filenames (SEO)
                    </label>
                    <div class="mt-1 text-xs text-slate-600">
                        Renames existing Plamod image files in-place to ASCII-only filenames.
                    </div>
                </div>

                <div
                    v-if="localError"
                    class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                >
                    {{ localError }}
                </div>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
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




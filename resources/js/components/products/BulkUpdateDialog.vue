<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { BulkUpdateProductChanges } from './ProductsTable.vue';

const EMPTY_MAIN_TYPE_VALUE = 'empty (no Shopify tags)';
const FILTER_EMPTY_MAIN_TYPE_TOKEN = '__empty__';

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
    mainTypeOptions?: string[];
    vendorOptions?: string[];
    typeOptions?: string[];
    gradeOptions?: string[];
    scaleOptions?: string[];
    seriesOptions?: string[];
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (
        e: 'confirm',
        payload: { changes: BulkUpdateProductChanges; renamePlamodAssets: boolean },
    ): void;
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
const mainType = ref<BulkFieldState<string>>({ apply: false, value: '' });
const type = ref<BulkFieldState<string>>({ apply: false, value: '' });
const grade = ref<BulkFieldState<string>>({ apply: false, value: '' });
const scale = ref<BulkFieldState<string>>({ apply: false, value: '' });
const series = ref<BulkFieldState<string>>({ apply: false, value: '' });
const vendor = ref<BulkFieldState<string>>({ apply: false, value: '' });
const publishedOnShopify = ref<BulkFieldState<'true' | 'false'>>({ apply: false, value: 'false' });
const latestArrival = ref<BulkFieldState<'true' | 'false'>>({ apply: false, value: 'false' });
const archiveStatus = ref<BulkFieldState<'archive' | 'unarchive'>>({
    apply: false,
    value: 'archive',
});
const availableQty = ref<BulkFieldState<string>>({ apply: false, value: '' });
const maintainQty = ref<BulkFieldState<string>>({ apply: false, value: '' });
const extended = ref<BulkFieldState<string>>({ apply: false, value: '' });
const renamePlamodAssets = ref(false);

function normalizeOptions(list: string[] | undefined, current: string): string[] {
    const base = (list ?? []).map((v) => String(v).trim()).filter((v) => v !== '');
    const cur = current.trim();
    const merged = cur !== '' ? [...base, cur] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
}

const vendorChoices = computed<string[]>(() =>
    normalizeOptions(props.vendorOptions, vendor.value.value),
);
const mainTypeChoices = computed<string[]>(() =>
    normalizeOptions(props.mainTypeOptions, mainType.value.value).filter(
        (v) => v !== FILTER_EMPTY_MAIN_TYPE_TOKEN,
    ),
);
const typeChoices = computed<string[]>(() => normalizeOptions(props.typeOptions, type.value.value));
const gradeChoices = computed<string[]>(() =>
    normalizeOptions(props.gradeOptions, grade.value.value),
);
const scaleChoices = computed<string[]>(() =>
    normalizeOptions(props.scaleOptions, scale.value.value),
);
const seriesChoices = computed<string[]>(() =>
    normalizeOptions(props.seriesOptions, series.value.value),
);

const hasAnyApply = computed<boolean>(() => {
    return (
        sku.value.apply ||
        barcode.value.apply ||
        description.value.apply ||
        handle.value.apply ||
        mainType.value.apply ||
        type.value.apply ||
        grade.value.apply ||
        scale.value.apply ||
        series.value.apply ||
        vendor.value.apply ||
        publishedOnShopify.value.apply ||
        latestArrival.value.apply ||
        archiveStatus.value.apply ||
        availableQty.value.apply ||
        maintainQty.value.apply ||
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
    mainType.value = { apply: false, value: '' };
    type.value = { apply: false, value: '' };
    grade.value = { apply: false, value: '' };
    scale.value = { apply: false, value: '' };
    series.value = { apply: false, value: '' };
    vendor.value = { apply: false, value: '' };
    publishedOnShopify.value = { apply: false, value: 'false' };
    latestArrival.value = { apply: false, value: 'false' };
    archiveStatus.value = { apply: false, value: 'archive' };
    availableQty.value = { apply: false, value: '' };
    maintainQty.value = { apply: false, value: '' };
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
    [
        sku,
        barcode,
        description,
        handle,
        mainType,
        type,
        grade,
        scale,
        series,
        vendor,
        publishedOnShopify,
        latestArrival,
        archiveStatus,
        availableQty,
        maintainQty,
        extended,
    ],
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

    if (mainType.value.apply) {
        const next = mainType.value.value.trim();
        if (next === '' || next.toLowerCase() === EMPTY_MAIN_TYPE_VALUE.toLowerCase()) {
            changes.main_type = null;
        } else {
            changes.main_type = next;
        }
    }

    if (type.value.apply) {
        const nextType = type.value.value.trim();
        changes.type = nextType === '' ? null : nextType;
    }

    if (grade.value.apply) {
        const next = grade.value.value.trim();
        changes.grade = next === '' ? null : next;
    }

    if (scale.value.apply) {
        const next = scale.value.value.trim();
        changes.scale = next === '' ? null : next;
    }

    if (series.value.apply) {
        const next = series.value.value.trim();
        changes.series = next === '' ? null : next;
    }

    if (vendor.value.apply) {
        const nextVendor = vendor.value.value.trim();
        changes.vendor = nextVendor === '' ? null : nextVendor;
    }

    if (publishedOnShopify.value.apply) {
        changes.published_on_shopify = publishedOnShopify.value.value === 'true';
    }

    if (latestArrival.value.apply) {
        changes.latest_arrival = latestArrival.value.value === 'true';
    }

    if (archiveStatus.value.apply) {
        changes.archived = archiveStatus.value.value === 'archive';
    }

    if (availableQty.value.apply) {
        const raw = availableQty.value.value.trim();
        if (raw === '') {
            changes.available = null;
        } else if (!/^\d+$/.test(raw)) {
            localError.value = 'Available quantity must be an integer.';
            return;
        } else {
            changes.available = Number.parseInt(raw, 10);
        }
    }

    if (maintainQty.value.apply) {
        const raw = maintainQty.value.value.trim();
        if (raw === '') {
            changes.maintain = null;
        } else if (!/^\d+$/.test(raw)) {
            localError.value = 'Maintain quantity must be an integer.';
            return;
        } else {
            changes.maintain = Number.parseInt(raw, 10);
        }
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
                            <input
                                v-model="barcode.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                            />
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
                            list="bulk-update-vendor-options"
                            :disabled="!vendor.apply || busy"
                        />
                        <datalist id="bulk-update-vendor-options">
                            <option v-for="v in vendorChoices" :key="v" :value="v" />
                        </datalist>
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="mainType.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-update-main-type-apply"
                            />
                            Main type
                        </label>
                        <input
                            v-model="mainType.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            list="bulk-update-main-type-options"
                            :disabled="!mainType.apply || busy"
                            placeholder="model kit"
                            data-testid="bulk-update-main-type-input"
                        />
                        <button
                            class="mt-1 text-xs font-medium text-slate-700 underline hover:text-slate-900 disabled:opacity-50"
                            type="button"
                            :disabled="!mainType.apply || busy"
                            data-testid="bulk-update-main-type-empty"
                            @click="mainType.value = EMPTY_MAIN_TYPE_VALUE"
                        >
                            Set empty (no Shopify tags)
                        </button>
                        <datalist id="bulk-update-main-type-options">
                            <option :value="EMPTY_MAIN_TYPE_VALUE" />
                            <option v-for="v in mainTypeChoices" :key="v" :value="v" />
                        </datalist>
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

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="latestArrival.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                            />
                            Latest arrival
                        </label>
                        <select
                            v-model="latestArrival.value"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            :disabled="!latestArrival.apply || busy"
                        >
                            <option value="true">True</option>
                            <option value="false">False</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="archiveStatus.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-archive-status-apply"
                            />
                            Archive status
                        </label>
                        <select
                            v-model="archiveStatus.value"
                            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            :disabled="!archiveStatus.apply || busy"
                            data-testid="bulk-archive-status-select"
                        >
                            <option value="archive">Archive</option>
                            <option value="unarchive">Unarchive</option>
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
                            list="bulk-update-type-options"
                            :disabled="!type.apply || busy"
                        />
                        <datalist id="bulk-update-type-options">
                            <option v-for="v in typeChoices" :key="v" :value="v" />
                        </datalist>
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="grade.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-grade-apply"
                            />
                            Grade
                        </label>
                        <input
                            v-model="grade.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            list="bulk-update-grade-options"
                            :disabled="!grade.apply || busy"
                            placeholder="(blank clears)"
                            data-testid="bulk-grade-value"
                        />
                        <datalist id="bulk-update-grade-options">
                            <option v-for="v in gradeChoices" :key="v" :value="v" />
                        </datalist>
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="scale.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-scale-apply"
                            />
                            Scale
                        </label>
                        <input
                            v-model="scale.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 font-mono text-xs"
                            type="text"
                            list="bulk-update-scale-options"
                            :disabled="!scale.apply || busy"
                            placeholder="(blank clears)"
                            data-testid="bulk-scale-value"
                        />
                        <datalist id="bulk-update-scale-options">
                            <option v-for="v in scaleChoices" :key="v" :value="v" />
                        </datalist>
                    </div>

                    <div class="md:col-span-6">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="series.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-series-apply"
                            />
                            Series
                        </label>
                        <input
                            v-model="series.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            list="bulk-update-series-options"
                            :disabled="!series.apply || busy"
                            placeholder="(blank clears)"
                            data-testid="bulk-series-value"
                        />
                        <datalist id="bulk-update-series-options">
                            <option v-for="v in seriesChoices" :key="v" :value="v" />
                        </datalist>
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="availableQty.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-available-apply"
                            />
                            Available qty
                        </label>
                        <input
                            v-model="availableQty.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            inputmode="numeric"
                            :disabled="!availableQty.apply || busy"
                            placeholder="(blank clears)"
                            data-testid="bulk-available-value"
                        />
                    </div>

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                v-model="maintainQty.apply"
                                type="checkbox"
                                class="h-4 w-4 rounded"
                                data-testid="bulk-maintain-apply"
                            />
                            Maintain qty
                        </label>
                        <input
                            v-model="maintainQty.value"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
                            type="text"
                            inputmode="numeric"
                            :disabled="!maintainQty.apply || busy"
                            placeholder="(blank clears)"
                            data-testid="bulk-maintain-value"
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
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                        Bulk actions
                    </div>
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-800">
                        <input
                            v-model="renamePlamodAssets"
                            type="checkbox"
                            class="h-4 w-4 rounded"
                            :disabled="busy"
                        />
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

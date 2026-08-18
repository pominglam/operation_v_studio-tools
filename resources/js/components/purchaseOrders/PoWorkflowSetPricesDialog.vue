<script setup lang="ts">
import { computed, reactive, ref, shallowRef, watch, type Ref } from 'vue';

import { useClientTableSort } from '../../composables/useClientTableSort';
import PoSetPriceSortableTh from './PoSetPriceSortableTh.vue';

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
    landed_cost_warning: string | null;
    new_prices: PoSetPricePreviewRow[];
    updates: PoSetPricePreviewRow[];
    unchanged: PoSetPricePreviewRow[];
    skipped_no_cost: PoSetPricePreviewRow[];
    apply_count: number;
};

export type PoSetPriceOverride = {
    product_uuid: string;
    price: string;
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    preview: PoSetPricePreview | null;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', payload: { overrides: PoSetPriceOverride[] }): void;
}>();

const overridePrices = reactive<Record<string, string>>({});
const unchangedHelpOpen = ref(false);

type PoSetPriceSortColumn = 'sku' | 'product' | 'po' | 'landed' | 'current' | 'mult' | 'override';
type PoSetPriceSkippedSortColumn = 'sku' | 'product' | 'po' | 'override';

const newPricesSort = useClientTableSort<PoSetPriceSortColumn>();
const updatesSort = useClientTableSort<PoSetPriceSortColumn>();
const unchangedSort = useClientTableSort<PoSetPriceSortColumn>();
const skippedSort = useClientTableSort<PoSetPriceSkippedSortColumn>();

const moneyPattern = /^\d+(\.\d{1,2})?$/;

function formatMoney(value: string | null | undefined): string {
    if (value == null || value.trim() === '') return '—';
    return `$${value}`;
}

function formatMultiplier(value: string | null | undefined): string {
    if (value == null || value.trim() === '') return '—';
    return `${value}x`;
}

function allPreviewRows(preview: PoSetPricePreview | null): PoSetPricePreviewRow[] {
    if (preview === null) return [];

    return [
        ...preview.new_prices,
        ...preview.updates,
        ...preview.unchanged,
        ...preview.skipped_no_cost,
    ];
}

function basePrice(row: PoSetPricePreviewRow): string {
    return row.proposed_price ?? row.current_price ?? '';
}

function overrideValue(row: PoSetPricePreviewRow): string {
    return overridePrices[row.product_uuid] ?? basePrice(row);
}

function setOverride(row: PoSetPricePreviewRow, value: string): void {
    const trimmed = value.trim();
    if (trimmed === basePrice(row)) {
        delete overridePrices[row.product_uuid];
        return;
    }

    overridePrices[row.product_uuid] = value;
}

function resetOverride(row: PoSetPricePreviewRow): void {
    delete overridePrices[row.product_uuid];
}

function useSuggestedPrice(row: PoSetPricePreviewRow): void {
    if (row.proposed_price === null) return;

    overridePrices[row.product_uuid] = row.proposed_price;
}

function isOverride(row: PoSetPricePreviewRow): boolean {
    return overridePrices[row.product_uuid] !== undefined;
}

function hasInvalidOverride(row: PoSetPricePreviewRow): boolean {
    const value = overridePrices[row.product_uuid];
    if (value === undefined) return false;

    const trimmed = value.trim();
    if (!moneyPattern.test(trimmed)) return true;

    const numericValue = Number(trimmed);
    return !Number.isFinite(numericValue) || numericValue > 99999.99;
}

function hasValidOverride(row: PoSetPricePreviewRow): boolean {
    return isOverride(row) && !hasInvalidOverride(row);
}

function normalizePrice(value: string): string {
    return Number(value.trim()).toFixed(2);
}

function multiplierFromPriceAndCost(price: string, cost: string | null): string | null {
    if (cost === null || cost.trim() === '') return null;

    const priceValue = Number(price.trim());
    const costValue = Number(cost.trim());
    if (!Number.isFinite(priceValue) || !Number.isFinite(costValue) || costValue <= 0) {
        return null;
    }

    return (priceValue / costValue).toFixed(2);
}

function effectiveMultiplier(row: PoSetPricePreviewRow, fallback: string | null): string | null {
    if (!hasValidOverride(row)) return fallback;

    return (
        multiplierFromPriceAndCost(overridePrices[row.product_uuid], row.landed_unit_cost) ??
        fallback
    );
}

function multiplierNeedsReview(value: string | null): boolean {
    if (value === null || value.trim() === '') return false;

    const multiplier = Number(value);
    return Number.isFinite(multiplier) && (multiplier < 1.45 || multiplier > 1.6);
}

function multiplierClass(value: string | null, manual = false): string {
    if (multiplierNeedsReview(value)) {
        return 'inline-flex rounded bg-rose-100 px-1.5 py-0.5 font-bold text-rose-800 ring-1 ring-inset ring-rose-300';
    }
    if (manual) return 'font-semibold text-amber-700';

    return '';
}

function parseSortNumber(value: string | null | undefined): number | null {
    if (value == null || value.trim() === '') return null;

    const numericValue = Number(value.trim());
    return Number.isFinite(numericValue) ? numericValue : null;
}

function compareNullableNumbers(left: number | null, right: number | null): number {
    if (left === null && right === null) return 0;
    if (left === null) return 1;
    if (right === null) return -1;

    return left - right;
}

function compareSku(left: PoSetPricePreviewRow, right: PoSetPricePreviewRow): number {
    const leftNumeric = parseSortNumber(left.sku);
    const rightNumeric = parseSortNumber(right.sku);
    if (leftNumeric !== null && rightNumeric !== null) {
        return leftNumeric - rightNumeric;
    }

    return left.sku.localeCompare(right.sku, undefined, { numeric: true, sensitivity: 'base' });
}

function compareRows(
    left: PoSetPricePreviewRow,
    right: PoSetPricePreviewRow,
    column: PoSetPriceSortColumn | PoSetPriceSkippedSortColumn,
    multiplierFallback: (row: PoSetPricePreviewRow) => string | null,
): number {
    switch (column) {
        case 'sku':
            return compareSku(left, right);
        case 'product':
            return (left.description ?? '').localeCompare(right.description ?? '', undefined, {
                sensitivity: 'base',
            });
        case 'po':
            return Number(left.is_new_on_po) - Number(right.is_new_on_po);
        case 'landed':
            return compareNullableNumbers(
                parseSortNumber(left.landed_unit_cost),
                parseSortNumber(right.landed_unit_cost),
            );
        case 'current':
            return compareNullableNumbers(
                parseSortNumber(left.current_price),
                parseSortNumber(right.current_price),
            );
        case 'mult':
            return compareNullableNumbers(
                parseSortNumber(effectiveMultiplier(left, multiplierFallback(left))),
                parseSortNumber(effectiveMultiplier(right, multiplierFallback(right))),
            );
        case 'override':
            return compareNullableNumbers(
                parseSortNumber(overrideValue(left)),
                parseSortNumber(overrideValue(right)),
            );
        default:
            return 0;
    }
}

function sortPreviewRows(
    rows: PoSetPricePreviewRow[],
    sortBy: PoSetPriceSortColumn | PoSetPriceSkippedSortColumn | null,
    sortDir: 'asc' | 'desc',
    multiplierFallback: (row: PoSetPricePreviewRow) => string | null,
): PoSetPricePreviewRow[] {
    if (sortBy === null) return rows;

    const column = sortBy;
    const direction = sortDir;

    return [...rows].sort((left, right) => {
        const result = compareRows(left, right, column, multiplierFallback);
        return direction === 'asc' ? result : -result;
    });
}

function useStableSortedPreviewRows<C extends PoSetPriceSortColumn | PoSetPriceSkippedSortColumn>(
    rowsSource: () => PoSetPricePreviewRow[],
    sortBy: Ref<C | null>,
    sortDir: Ref<'asc' | 'desc'>,
    multiplierFallback: (row: PoSetPricePreviewRow) => string | null,
): Ref<PoSetPricePreviewRow[]> {
    const sorted = shallowRef<PoSetPricePreviewRow[]>([]);

    watch(
        () => [rowsSource(), sortBy.value, sortDir.value] as const,
        () => {
            sorted.value = sortPreviewRows(
                rowsSource(),
                sortBy.value,
                sortDir.value,
                multiplierFallback,
            );
        },
        { immediate: true },
    );

    return sorted;
}

function resetAllSorts(): void {
    newPricesSort.reset();
    updatesSort.reset();
    unchangedSort.reset();
    skippedSort.reset();
}

const sortedNewPrices = useStableSortedPreviewRows(
    () => props.preview?.new_prices ?? [],
    newPricesSort.sortBy,
    newPricesSort.sortDir,
    (row) => row.proposed_multiplier,
);

const sortedUpdates = useStableSortedPreviewRows(
    () => props.preview?.updates ?? [],
    updatesSort.sortBy,
    updatesSort.sortDir,
    (row) => row.proposed_multiplier,
);

const sortedUnchanged = useStableSortedPreviewRows(
    () => props.preview?.unchanged ?? [],
    unchangedSort.sortBy,
    unchangedSort.sortDir,
    (row) => row.current_multiplier,
);

const sortedSkippedNoCost = useStableSortedPreviewRows(
    () => props.preview?.skipped_no_cost ?? [],
    skippedSort.sortBy,
    skippedSort.sortDir,
    () => null,
);

function shouldShowMultiplierArrow(
    row: PoSetPricePreviewRow,
    targetMultiplier: string | null,
): boolean {
    if (
        row.current_multiplier === null ||
        targetMultiplier === null ||
        row.current_multiplier.trim() === ''
    ) {
        return false;
    }

    return hasValidOverride(row) || row.current_multiplier !== targetMultiplier;
}

const overrides = computed<PoSetPriceOverride[]>(() =>
    allPreviewRows(props.preview)
        .filter((row) => isOverride(row) && !hasInvalidOverride(row))
        .map((row) => ({
            product_uuid: row.product_uuid,
            price: normalizePrice(overridePrices[row.product_uuid]),
        })),
);

const hasInvalidOverrides = computed<boolean>(() =>
    allPreviewRows(props.preview).some((row) => hasInvalidOverride(row)),
);

const canApply = computed<boolean>(
    () =>
        props.preview !== null &&
        !hasInvalidOverrides.value &&
        (props.preview.apply_count > 0 || overrides.value.length > 0),
);

function confirm(): void {
    if (!canApply.value) return;

    emit('confirm', { overrides: overrides.value });
}

watch(
    () => props.preview,
    () => {
        unchangedHelpOpen.value = false;
        resetAllSorts();
        for (const key of Object.keys(overridePrices)) {
            delete overridePrices[key];
        }
    },
);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            unchangedHelpOpen.value = false;
        }
    },
);
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
            <div class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-lg bg-white shadow-xl">
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">
                        Set/review selling prices
                    </div>
                    <div v-if="preview" class="mt-1 text-xs text-slate-600">
                        Formula: landed cost × {{ preview.multiplier }}, rounded up to the next X.99
                        CAD at or above that target; we then suggest one X.99 tier lower only when
                        that formula price is over 1.55× landed and the lower tier remains at least
                        1.45×.
                        <span class="font-semibold text-slate-900">{{ preview.apply_count }}</span>
                        product(s) will change if you apply.
                        <span v-if="overrides.length" class="font-semibold text-amber-800">
                            {{ overrides.length }} manual override(s).
                        </span>
                    </div>
                    <div
                        v-if="preview?.landed_cost_warning"
                        class="mt-1 text-xs font-semibold text-amber-700"
                    >
                        {{ preview.landed_cost_warning }}
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="error" class="mb-3 text-sm text-rose-700">{{ error }}</p>

                    <template v-if="preview">
                        <section v-if="preview.new_prices.length" class="mb-4">
                            <div
                                class="text-xs font-semibold uppercase tracking-wide text-emerald-800"
                            >
                                New prices ({{ preview.new_prices.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead
                                    class="sticky top-0 z-10 bg-white text-slate-500 shadow-[0_1px_0_0_rgb(226_232_240)]"
                                >
                                    <tr>
                                        <PoSetPriceSortableTh
                                            label="SKU"
                                            :indicator="newPricesSort.sortIndicator('sku')"
                                            :header-class="newPricesSort.headerClass('sku')"
                                            :sort-action="() => newPricesSort.toggleSort('sku')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Product"
                                            :indicator="newPricesSort.sortIndicator('product')"
                                            :header-class="newPricesSort.headerClass('product')"
                                            :sort-action="() => newPricesSort.toggleSort('product')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="PO"
                                            :indicator="newPricesSort.sortIndicator('po')"
                                            :header-class="newPricesSort.headerClass('po')"
                                            :sort-action="() => newPricesSort.toggleSort('po')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Landed"
                                            :indicator="newPricesSort.sortIndicator('landed')"
                                            :header-class="newPricesSort.headerClass('landed')"
                                            :sort-action="() => newPricesSort.toggleSort('landed')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Current"
                                            :indicator="newPricesSort.sortIndicator('current')"
                                            :header-class="newPricesSort.headerClass('current')"
                                            :sort-action="() => newPricesSort.toggleSort('current')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Mult."
                                            :indicator="newPricesSort.sortIndicator('mult')"
                                            :header-class="newPricesSort.headerClass('mult')"
                                            :sort-action="() => newPricesSort.toggleSort('mult')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Proposed"
                                            :indicator="newPricesSort.sortIndicator('override')"
                                            :header-class="newPricesSort.headerClass('override')"
                                            :sort-action="
                                                () => newPricesSort.toggleSort('override')
                                            "
                                        />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in sortedNewPrices"
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
                                            <span
                                                :class="
                                                    multiplierClass(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                        hasValidOverride(row),
                                                    )
                                                "
                                            >
                                                {{
                                                    formatMultiplier(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                    )
                                                }}
                                            </span>
                                        </td>
                                        <td class="py-1.5">
                                            <div class="flex items-center gap-1">
                                                <span class="text-slate-500">$</span>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="w-20 rounded border border-slate-200 px-2 py-1 text-right font-semibold text-emerald-800 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                                                    :class="{
                                                        'border-rose-300 text-rose-700':
                                                            hasInvalidOverride(row),
                                                        'bg-amber-50': isOverride(row),
                                                    }"
                                                    :value="overrideValue(row)"
                                                    :aria-label="`Override price for ${row.sku}`"
                                                    @input="
                                                        setOverride(
                                                            row,
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        )
                                                    "
                                                />
                                                <button
                                                    v-if="isOverride(row)"
                                                    type="button"
                                                    class="text-[11px] font-medium text-slate-500 hover:text-slate-800"
                                                    @click="resetOverride(row)"
                                                >
                                                    Reset
                                                </button>
                                            </div>
                                            <div
                                                v-if="hasInvalidOverride(row)"
                                                class="mt-1 text-[11px] text-rose-700"
                                            >
                                                Use 0.00 format.
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section v-if="preview.updates.length" class="mb-4">
                            <div
                                class="text-xs font-semibold uppercase tracking-wide text-amber-800"
                            >
                                Price updates ({{ preview.updates.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead
                                    class="sticky top-0 z-10 bg-white text-slate-500 shadow-[0_1px_0_0_rgb(226_232_240)]"
                                >
                                    <tr>
                                        <PoSetPriceSortableTh
                                            label="SKU"
                                            :indicator="updatesSort.sortIndicator('sku')"
                                            :header-class="updatesSort.headerClass('sku')"
                                            :sort-action="() => updatesSort.toggleSort('sku')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Product"
                                            :indicator="updatesSort.sortIndicator('product')"
                                            :header-class="updatesSort.headerClass('product')"
                                            :sort-action="() => updatesSort.toggleSort('product')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="PO"
                                            :indicator="updatesSort.sortIndicator('po')"
                                            :header-class="updatesSort.headerClass('po')"
                                            :sort-action="() => updatesSort.toggleSort('po')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Landed"
                                            :indicator="updatesSort.sortIndicator('landed')"
                                            :header-class="updatesSort.headerClass('landed')"
                                            :sort-action="() => updatesSort.toggleSort('landed')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Current"
                                            :indicator="updatesSort.sortIndicator('current')"
                                            :header-class="updatesSort.headerClass('current')"
                                            :sort-action="() => updatesSort.toggleSort('current')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Mult."
                                            :indicator="updatesSort.sortIndicator('mult')"
                                            :header-class="updatesSort.headerClass('mult')"
                                            :sort-action="() => updatesSort.toggleSort('mult')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Proposed"
                                            :indicator="updatesSort.sortIndicator('override')"
                                            :header-class="updatesSort.headerClass('override')"
                                            :sort-action="() => updatesSort.toggleSort('override')"
                                        />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in sortedUpdates"
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
                                            <template
                                                v-if="
                                                    shouldShowMultiplierArrow(
                                                        row,
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                    )
                                                "
                                            >
                                                <span
                                                    :class="multiplierClass(row.current_multiplier)"
                                                >
                                                    {{ formatMultiplier(row.current_multiplier) }}
                                                </span>
                                                <span class="text-slate-500"> → </span>
                                                <span
                                                    :class="
                                                        multiplierClass(
                                                            effectiveMultiplier(
                                                                row,
                                                                row.proposed_multiplier,
                                                            ),
                                                            hasValidOverride(row),
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatMultiplier(
                                                            effectiveMultiplier(
                                                                row,
                                                                row.proposed_multiplier,
                                                            ),
                                                        )
                                                    }}
                                                </span>
                                            </template>
                                            <span
                                                v-else
                                                :class="
                                                    multiplierClass(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                        hasValidOverride(row),
                                                    )
                                                "
                                            >
                                                {{
                                                    formatMultiplier(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                    )
                                                }}
                                            </span>
                                        </td>
                                        <td class="py-1.5">
                                            <div class="flex items-center gap-1">
                                                <span class="text-slate-500">$</span>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="w-20 rounded border border-slate-200 px-2 py-1 text-right font-semibold text-amber-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                                                    :class="{
                                                        'border-rose-300 text-rose-700':
                                                            hasInvalidOverride(row),
                                                        'bg-amber-50': isOverride(row),
                                                    }"
                                                    :value="overrideValue(row)"
                                                    :aria-label="`Override price for ${row.sku}`"
                                                    @input="
                                                        setOverride(
                                                            row,
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        )
                                                    "
                                                />
                                                <button
                                                    v-if="isOverride(row)"
                                                    type="button"
                                                    class="text-[11px] font-medium text-slate-500 hover:text-slate-800"
                                                    @click="resetOverride(row)"
                                                >
                                                    Reset
                                                </button>
                                                <button
                                                    v-else-if="row.proposed_price !== null"
                                                    type="button"
                                                    class="whitespace-nowrap rounded border border-slate-300 bg-white px-1.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50"
                                                    :aria-label="`Use suggested price for ${row.sku}`"
                                                    @click="useSuggestedPrice(row)"
                                                >
                                                    Use suggested
                                                </button>
                                            </div>
                                            <div
                                                v-if="hasInvalidOverride(row)"
                                                class="mt-1 text-[11px] text-rose-700"
                                            >
                                                Use 0.00 format.
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section v-if="preview.unchanged.length" class="mb-4">
                            <div
                                class="text-xs font-semibold uppercase tracking-wide text-slate-600"
                            >
                                No price change ({{ preview.unchanged.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead
                                    class="sticky top-0 z-10 bg-white text-slate-500 shadow-[0_1px_0_0_rgb(226_232_240)]"
                                >
                                    <tr>
                                        <PoSetPriceSortableTh
                                            label="SKU"
                                            :indicator="unchangedSort.sortIndicator('sku')"
                                            :header-class="unchangedSort.headerClass('sku')"
                                            :sort-action="() => unchangedSort.toggleSort('sku')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Product"
                                            :indicator="unchangedSort.sortIndicator('product')"
                                            :header-class="unchangedSort.headerClass('product')"
                                            :sort-action="() => unchangedSort.toggleSort('product')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="PO"
                                            :indicator="unchangedSort.sortIndicator('po')"
                                            :header-class="unchangedSort.headerClass('po')"
                                            :sort-action="() => unchangedSort.toggleSort('po')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Landed"
                                            test-id="po-set-prices-unchanged-landed"
                                            :indicator="unchangedSort.sortIndicator('landed')"
                                            :header-class="unchangedSort.headerClass('landed')"
                                            :sort-action="() => unchangedSort.toggleSort('landed')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Current"
                                            :indicator="unchangedSort.sortIndicator('current')"
                                            :header-class="unchangedSort.headerClass('current')"
                                            :sort-action="() => unchangedSort.toggleSort('current')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Mult."
                                            :indicator="unchangedSort.sortIndicator('mult')"
                                            :header-class="unchangedSort.headerClass('mult')"
                                            :sort-action="() => unchangedSort.toggleSort('mult')"
                                        />
                                        <th class="py-1">
                                            <span class="inline-flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    class="hover:underline"
                                                    :class="unchangedSort.headerClass('override')"
                                                    @click="
                                                        () => unchangedSort.toggleSort('override')
                                                    "
                                                >
                                                    Override{{
                                                        unchangedSort.sortIndicator('override')
                                                    }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-slate-300 text-[10px] font-bold leading-none text-slate-500 hover:border-slate-400 hover:text-slate-700 focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                                                    :aria-expanded="unchangedHelpOpen"
                                                    aria-controls="po-set-prices-unchanged-help"
                                                    aria-label="Why can Current and Override differ?"
                                                    @click="unchangedHelpOpen = !unchangedHelpOpen"
                                                >
                                                    ?
                                                </button>
                                            </span>
                                        </th>
                                    </tr>
                                    <tr v-if="unchangedHelpOpen">
                                        <th
                                            id="po-set-prices-unchanged-help"
                                            colspan="7"
                                            class="pb-2 font-normal normal-case leading-relaxed text-slate-600"
                                        >
                                            Apply won't update these SKUs unless you edit Override
                                            or click Use suggested. Current is the catalog price.
                                            Override shows the formula price; when Current is higher
                                            than the formula, we keep Current.
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in sortedUnchanged"
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
                                            <template
                                                v-if="
                                                    shouldShowMultiplierArrow(
                                                        row,
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                    )
                                                "
                                            >
                                                <span
                                                    :class="multiplierClass(row.current_multiplier)"
                                                >
                                                    {{ formatMultiplier(row.current_multiplier) }}
                                                </span>
                                                <span class="text-slate-500"> → </span>
                                                <span
                                                    :class="
                                                        multiplierClass(
                                                            effectiveMultiplier(
                                                                row,
                                                                row.proposed_multiplier,
                                                            ),
                                                            hasValidOverride(row),
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatMultiplier(
                                                            effectiveMultiplier(
                                                                row,
                                                                row.proposed_multiplier,
                                                            ),
                                                        )
                                                    }}
                                                </span>
                                            </template>
                                            <span
                                                v-else
                                                :class="
                                                    multiplierClass(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                        hasValidOverride(row),
                                                    )
                                                "
                                            >
                                                {{
                                                    formatMultiplier(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.proposed_multiplier,
                                                        ),
                                                    )
                                                }}
                                            </span>
                                        </td>
                                        <td class="py-1.5">
                                            <div class="flex items-center gap-1">
                                                <span class="text-slate-500">$</span>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="w-20 rounded border border-slate-200 px-2 py-1 text-right font-semibold text-slate-800 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                                                    :class="{
                                                        'border-rose-300 text-rose-700':
                                                            hasInvalidOverride(row),
                                                        'bg-amber-50': isOverride(row),
                                                    }"
                                                    :value="overrideValue(row)"
                                                    :aria-label="`Override price for ${row.sku}`"
                                                    @input="
                                                        setOverride(
                                                            row,
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        )
                                                    "
                                                />
                                                <button
                                                    v-if="isOverride(row)"
                                                    type="button"
                                                    class="text-[11px] font-medium text-slate-500 hover:text-slate-800"
                                                    @click="resetOverride(row)"
                                                >
                                                    Reset
                                                </button>
                                                <button
                                                    v-else-if="row.proposed_price !== null"
                                                    type="button"
                                                    class="whitespace-nowrap rounded border border-slate-300 bg-white px-1.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50"
                                                    :aria-label="`Use suggested price for ${row.sku}`"
                                                    @click="useSuggestedPrice(row)"
                                                >
                                                    Use suggested
                                                </button>
                                            </div>
                                            <div
                                                v-if="hasInvalidOverride(row)"
                                                class="mt-1 text-[11px] text-rose-700"
                                            >
                                                Use 0.00 format.
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section v-if="preview.skipped_no_cost.length">
                            <div
                                class="text-xs font-semibold uppercase tracking-wide text-slate-500"
                            >
                                Missing landed cost — cannot price ({{
                                    preview.skipped_no_cost.length
                                }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead
                                    class="sticky top-0 z-10 bg-white text-slate-500 shadow-[0_1px_0_0_rgb(226_232_240)]"
                                >
                                    <tr>
                                        <PoSetPriceSortableTh
                                            label="SKU"
                                            :indicator="skippedSort.sortIndicator('sku')"
                                            :header-class="skippedSort.headerClass('sku')"
                                            :sort-action="() => skippedSort.toggleSort('sku')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Product"
                                            :indicator="skippedSort.sortIndicator('product')"
                                            :header-class="skippedSort.headerClass('product')"
                                            :sort-action="() => skippedSort.toggleSort('product')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="PO"
                                            :indicator="skippedSort.sortIndicator('po')"
                                            :header-class="skippedSort.headerClass('po')"
                                            :sort-action="() => skippedSort.toggleSort('po')"
                                        />
                                        <PoSetPriceSortableTh
                                            label="Override"
                                            :indicator="skippedSort.sortIndicator('override')"
                                            :header-class="skippedSort.headerClass('override')"
                                            :sort-action="() => skippedSort.toggleSort('override')"
                                        />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in sortedSkippedNoCost"
                                        :key="row.product_uuid"
                                        class="border-t border-slate-100 text-slate-700"
                                    >
                                        <td class="py-1.5 pr-2 font-medium text-slate-800">
                                            {{ row.sku }}
                                        </td>
                                        <td
                                            class="max-w-[14rem] py-1.5 pr-2"
                                            :title="row.description"
                                        >
                                            {{ row.description || '—' }}
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            {{ row.is_new_on_po ? 'New' : 'Existing' }}
                                        </td>
                                        <td class="py-1.5">
                                            <div class="flex items-center gap-1">
                                                <span class="text-slate-500">$</span>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    class="w-20 rounded border border-slate-200 px-2 py-1 text-right font-semibold text-slate-800 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                                                    :class="{
                                                        'border-rose-300 text-rose-700':
                                                            hasInvalidOverride(row),
                                                        'bg-amber-50': isOverride(row),
                                                    }"
                                                    :value="overrideValue(row)"
                                                    :aria-label="`Override price for ${row.sku}`"
                                                    placeholder="0.00"
                                                    @input="
                                                        setOverride(
                                                            row,
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        )
                                                    "
                                                />
                                                <button
                                                    v-if="isOverride(row)"
                                                    type="button"
                                                    class="text-[11px] font-medium text-slate-500 hover:text-slate-800"
                                                    @click="resetOverride(row)"
                                                >
                                                    Reset
                                                </button>
                                            </div>
                                            <div
                                                v-if="hasInvalidOverride(row)"
                                                class="mt-1 text-[11px] text-rose-700"
                                            >
                                                Use 0.00 format.
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
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
                        :disabled="busy || !canApply"
                        @click="confirm"
                    >
                        {{ busy ? 'Applying…' : 'Apply prices' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

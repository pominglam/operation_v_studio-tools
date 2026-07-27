<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

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
                        Formula: landed cost × {{ preview.multiplier }}, rounded to X.99 CAD.
                        <span class="font-semibold text-slate-900">{{ preview.apply_count }}</span>
                        product(s) will change if you apply.
                        <span v-if="overrides.length" class="font-semibold text-amber-800">
                            {{ overrides.length }} manual override(s).
                        </span>
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
                                        <td class="py-1.5 pr-2">
                                            {{ formatMoney(row.current_price) }}
                                        </td>
                                        <td class="py-1.5 pr-2 tabular-nums">
                                            <span
                                                :class="{
                                                    'font-semibold text-amber-700':
                                                        hasValidOverride(row),
                                                }"
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
                                                <span>{{
                                                    formatMultiplier(row.current_multiplier)
                                                }}</span>
                                                <span class="text-slate-500"> → </span>
                                                <span
                                                    :class="
                                                        hasValidOverride(row)
                                                            ? 'font-semibold text-amber-700'
                                                            : ''
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
                                                :class="{
                                                    'font-semibold text-amber-700':
                                                        hasValidOverride(row),
                                                }"
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
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="py-1 pr-2">SKU</th>
                                        <th class="py-1 pr-2">Product</th>
                                        <th class="py-1 pr-2">PO</th>
                                        <th class="py-1 pr-2">Landed</th>
                                        <th class="py-1 pr-2">Current</th>
                                        <th class="py-1 pr-2">Mult.</th>
                                        <th class="py-1">
                                            <span class="inline-flex items-center gap-1">
                                                Override
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
                                            Apply won't update these SKUs unless you edit Override.
                                            Current is the catalog price. Override shows the formula
                                            price; when Current is higher than the formula, we keep
                                            Current.
                                        </th>
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
                                            <template
                                                v-if="
                                                    shouldShowMultiplierArrow(
                                                        row,
                                                        effectiveMultiplier(
                                                            row,
                                                            row.current_multiplier,
                                                        ),
                                                    )
                                                "
                                            >
                                                <span>{{
                                                    formatMultiplier(row.current_multiplier)
                                                }}</span>
                                                <span class="text-slate-500"> → </span>
                                                <span
                                                    :class="
                                                        hasValidOverride(row)
                                                            ? 'font-semibold text-amber-700'
                                                            : ''
                                                    "
                                                >
                                                    {{
                                                        formatMultiplier(
                                                            effectiveMultiplier(
                                                                row,
                                                                row.current_multiplier,
                                                            ),
                                                        )
                                                    }}
                                                </span>
                                            </template>
                                            <span
                                                v-else
                                                :class="{
                                                    'font-semibold text-amber-700':
                                                        hasValidOverride(row),
                                                }"
                                            >
                                                {{
                                                    formatMultiplier(
                                                        effectiveMultiplier(
                                                            row,
                                                            row.current_multiplier,
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
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="py-1 pr-2">SKU</th>
                                        <th class="py-1 pr-2">Product</th>
                                        <th class="py-1 pr-2">PO</th>
                                        <th class="py-1">Override</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in preview.skipped_no_cost"
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

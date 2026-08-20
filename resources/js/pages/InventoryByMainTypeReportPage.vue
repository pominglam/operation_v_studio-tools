<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ColumnHeaderHelp from '../components/ColumnHeaderHelp.vue';
import { api } from '../lib/api';
import {
    formatInventoryLandedValue,
    groupInventoryRowsByStorefrontNavbar,
    parseInventoryByMainTypeReportResponse,
    type InventoryByMainTypeReport,
    type InventoryByMainTypeNavbarGroup,
    type InventoryByMainTypeSortKey,
} from '../lib/inventoryByMainTypeReport';
import {
    buildInventoryByMainTypeProductsUrl,
    drillDownTargetFromReportRow,
    type InventoryReportUniqueSkuSlice,
} from '../lib/inventoryByMainTypeProductsLinks';
import type { InventoryByMainTypeReportRow } from '../lib/inventoryByMainTypeReport';

const loading = ref(false);
const error = ref<string | null>(null);
const report = ref<InventoryByMainTypeReport | null>(null);
const sortBy = ref<InventoryByMainTypeSortKey>('type_label');
const sortDir = ref<'asc' | 'desc'>('asc');
const expandedGroups = ref<Set<string>>(
    new Set(['model-kits', 'tools-supplies', 'water-decals', 'miscellaneous', 'other']),
);

const currency = computed<string>(() => report.value?.currency ?? 'CAD');
const totals = computed(() => report.value?.totals ?? null);

const groupedRows = computed<InventoryByMainTypeNavbarGroup[]>(() =>
    groupInventoryRowsByStorefrontNavbar(report.value?.rows ?? [], sortBy.value, sortDir.value),
);

const missingLandedTotal = computed<number>(() => totals.value?.skus_missing_landed_cost ?? 0);

function toggleSort(column: InventoryByMainTypeSortKey): void {
    if (sortBy.value === column) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }

    sortBy.value = column;
    sortDir.value = column === 'type_label' ? 'asc' : 'desc';
}

function sortIndicator(column: InventoryByMainTypeSortKey): string {
    if (sortBy.value !== column) {
        return '';
    }

    return sortDir.value === 'asc' ? ' ↑' : ' ↓';
}

function sortHeaderClass(column: InventoryByMainTypeSortKey): string {
    return sortBy.value === column ? 'text-slate-900' : 'text-slate-600';
}

function formatMoney(amount: string): string {
    return formatInventoryLandedValue(amount, currency.value);
}

function uniqueSkuCountHref(
    slice: InventoryReportUniqueSkuSlice,
    row: InventoryByMainTypeReportRow | null,
): string {
    return buildInventoryByMainTypeProductsUrl(
        slice,
        row === null ? null : drillDownTargetFromReportRow(row),
    );
}

function canDrillDownCount(value: number): boolean {
    return value > 0;
}

function isGroupExpanded(groupKey: string): boolean {
    return expandedGroups.value.has(groupKey);
}

function toggleGroup(groupKey: string): void {
    const next = new Set(expandedGroups.value);
    if (next.has(groupKey)) {
        next.delete(groupKey);
    } else {
        next.add(groupKey);
    }
    expandedGroups.value = next;
}

function typeTestId(type: string): string {
    const suffix = type
        .trim()
        .toLocaleLowerCase()
        .replace(/[^a-z0-9]+/g, '-');
    return `type-row-${suffix || 'unset'}`;
}

const stickyHeaderGroupClass =
    'sticky top-0 z-30 bg-slate-50 shadow-[0_1px_0_0_rgb(226_232_240)]';
const stickyHeaderColumnClass =
    'sticky top-[2.125rem] z-20 bg-slate-50 shadow-[0_1px_0_0_rgb(226_232_240)]';

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const res = await api.get('/api/v1/reports/inventory-by-main-type');
        report.value = parseInventoryByMainTypeReportResponse(res.data);
    } catch (e: unknown) {
        if (e instanceof Error && e.message.includes('Inventory by type report')) {
            error.value = e.message;
        } else {
            error.value = 'Failed to load inventory by type report.';
        }
        report.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Inventory by type</h2>
                <p class="mt-1 text-sm text-slate-600">
                    On-hand columns use
                    <code class="rounded bg-slate-100 px-1">available_qty</code>. Not arrived sums
                    PO line qty until the PO is fully on shelves (includes draft POs, same as
                    Products grid default).
                </p>
            </div>

            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="loading"
                @click="load"
            >
                Refresh
            </button>
        </div>

        <div v-if="!loading && !error && totals" class="text-sm text-slate-700">
            {{ totals.skus_on_hand }} SKU(s) on hand · {{ totals.quantity_on_hand }} units ·
            estimated landed {{ formatMoney(totals.estimated_landed_value) }} · not arrived
            {{ formatMoney(totals.estimated_not_landed_value) }} · {{ totals.not_arrived }} units
            not arrived
        </div>

        <div
            v-if="!loading && !error && missingLandedTotal > 0"
            class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ missingLandedTotal }} on-hand SKU(s) are missing
            <code class="rounded bg-white px-1">latest_landed_unit_cost</code>, so landed totals
            exclude those lines. Run Maintenance → refresh latest costs if needed.
        </div>

        <div class="rounded-lg border border-slate-200 bg-white">
            <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading report…</div>
            <div v-else-if="error" class="px-4 py-3 text-sm text-rose-700">{{ error }}</div>

            <div v-else data-testid="inventory-by-type-table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr
                            class="border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th
                                rowspan="2"
                                class="cursor-pointer px-4 py-3 align-bottom text-slate-600"
                                :class="[sortHeaderClass('type_label'), stickyHeaderGroupClass]"
                                @click="toggleSort('type_label')"
                            >
                                Type{{ sortIndicator('type_label') }}
                            </th>
                            <th
                                rowspan="2"
                                class="cursor-pointer border-l border-slate-200 px-4 py-3 text-right align-bottom tabular-nums text-slate-600"
                                :class="[sortHeaderClass('catalog_skus'), stickyHeaderGroupClass]"
                                @click="toggleSort('catalog_skus')"
                            >
                                Catalog SKUs{{ sortIndicator('catalog_skus') }}
                            </th>
                            <th
                                colspan="2"
                                class="border-l border-slate-200 px-4 py-2 text-center text-slate-700"
                                :class="stickyHeaderGroupClass"
                            >
                                Received / sold
                            </th>
                            <th
                                colspan="4"
                                class="border-l border-slate-200 px-4 py-2 text-center text-slate-700"
                                :class="stickyHeaderGroupClass"
                            >
                                On hand
                            </th>
                            <th
                                colspan="3"
                                class="border-l border-slate-200 px-4 py-2 text-center text-slate-700"
                                :class="stickyHeaderGroupClass"
                            >
                                Not arrived
                            </th>
                        </tr>
                        <tr
                            class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600"
                        >
                            <th
                                class="cursor-pointer border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('units_received'), stickyHeaderColumnClass]"
                                @click="toggleSort('units_received')"
                            >
                                <span class="inline-flex items-center justify-end gap-1">
                                    Received{{ sortIndicator('units_received') }}
                                    <ColumnHeaderHelp
                                        label="Lifetime units received on PO lines where the PO has a received date (Products grid Total ordered)."
                                    />
                                </span>
                            </th>
                            <th
                                class="cursor-pointer px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('units_sold'), stickyHeaderColumnClass]"
                                @click="toggleSort('units_sold')"
                            >
                                <span class="inline-flex items-center justify-end gap-1">
                                    Sold{{ sortIndicator('units_sold') }}
                                    <ColumnHeaderHelp
                                        label="Received units minus current available_qty per SKU, summed by type (Products grid Total sold)."
                                    />
                                </span>
                            </th>
                            <th
                                class="cursor-pointer border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('skus_on_hand'), stickyHeaderColumnClass]"
                                @click="toggleSort('skus_on_hand')"
                            >
                                SKUs{{ sortIndicator('skus_on_hand') }}
                            </th>
                            <th
                                class="cursor-pointer px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('quantity_on_hand'), stickyHeaderColumnClass]"
                                @click="toggleSort('quantity_on_hand')"
                            >
                                Units{{ sortIndicator('quantity_on_hand') }}
                            </th>
                            <th
                                class="cursor-pointer px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('estimated_landed_value'), stickyHeaderColumnClass]"
                                @click="toggleSort('estimated_landed_value')"
                            >
                                Value ({{ currency }}){{ sortIndicator('estimated_landed_value') }}
                            </th>
                            <th
                                class="cursor-pointer px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('skus_missing_landed_cost'), stickyHeaderColumnClass]"
                                @click="toggleSort('skus_missing_landed_cost')"
                            >
                                <span class="inline-flex items-center justify-end gap-1">
                                    Missing landed{{ sortIndicator('skus_missing_landed_cost') }}
                                    <ColumnHeaderHelp
                                        label="On-hand SKUs with no cached latest_landed_unit_cost. Their units are excluded from Value."
                                    />
                                </span>
                            </th>
                            <th
                                class="cursor-pointer border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('not_arrived_skus'), stickyHeaderColumnClass]"
                                @click="toggleSort('not_arrived_skus')"
                            >
                                <span class="inline-flex items-center justify-end gap-1">
                                    SKUs{{ sortIndicator('not_arrived_skus') }}
                                    <ColumnHeaderHelp
                                        label="Unique active SKUs with not-arrived PO qty &gt; 0. Includes draft POs (Products grid default)."
                                    />
                                </span>
                            </th>
                            <th
                                class="cursor-pointer px-4 py-3 text-right tabular-nums"
                                :class="[sortHeaderClass('not_arrived'), stickyHeaderColumnClass]"
                                @click="toggleSort('not_arrived')"
                            >
                                <span class="inline-flex items-center justify-end gap-1">
                                    Units{{ sortIndicator('not_arrived') }}
                                    <ColumnHeaderHelp
                                        label="Sum of qty ordered on PO lines until the PO is fully on shelves. Includes draft POs."
                                    />
                                </span>
                            </th>
                            <th
                                class="cursor-pointer px-4 py-3 text-right tabular-nums"
                                :class="[
                                    sortHeaderClass('estimated_not_landed_value'),
                                    stickyHeaderColumnClass,
                                ]"
                                @click="toggleSort('estimated_not_landed_value')"
                            >
                                <span class="inline-flex items-center justify-end gap-1">
                                    Value ({{ currency }}){{
                                        sortIndicator('estimated_not_landed_value')
                                    }}
                                    <ColumnHeaderHelp
                                        label="Not arrived units × latest_landed_unit_cost per SKU. Rows without landed cost are excluded."
                                    />
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-for="group in groupedRows" :key="group.key">
                            <tr class="bg-slate-100/80 font-semibold text-slate-900">
                                <td class="px-4 py-3">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-2 text-left hover:text-blue-700"
                                        :aria-expanded="isGroupExpanded(group.key)"
                                        :aria-label="`${isGroupExpanded(group.key) ? 'Collapse' : 'Expand'} ${group.label}`"
                                        @click="toggleGroup(group.key)"
                                    >
                                        <svg
                                            class="h-3.5 w-3.5 shrink-0 transition-transform"
                                            :class="{ 'rotate-90': isGroupExpanded(group.key) }"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.5"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="m9 18 6-6-6-6"
                                            />
                                        </svg>
                                        {{ group.label }}
                                    </button>
                                </td>
                                <td
                                    class="border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                >
                                    {{ group.totals.catalog_skus }}
                                </td>
                                <td
                                    class="border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                >
                                    {{ group.totals.units_received }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ group.totals.units_sold }}
                                </td>
                                <td
                                    class="border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                >
                                    {{ group.totals.skus_on_hand }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ group.totals.quantity_on_hand }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatMoney(group.totals.estimated_landed_value) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ group.totals.skus_missing_landed_cost }}
                                </td>
                                <td
                                    class="border-l border-slate-200 px-4 py-3 text-right tabular-nums"
                                >
                                    {{ group.totals.not_arrived_skus }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ group.totals.not_arrived }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatMoney(group.totals.estimated_not_landed_value) }}
                                </td>
                            </tr>
                            <tr
                                v-for="row in isGroupExpanded(group.key) ? group.rows : []"
                                :key="`${group.key}:${row.type}`"
                                :data-testid="typeTestId(row.type)"
                                class="hover:bg-slate-50"
                            >
                                <td class="py-3 pr-4 pl-9 text-slate-900">
                                    {{ row.type_label }}
                                </td>
                                <td
                                    class="border-l border-slate-100 px-4 py-3 text-right tabular-nums text-slate-900"
                                >
                                    <a
                                        v-if="canDrillDownCount(row.catalog_skus)"
                                        :href="uniqueSkuCountHref('catalog_skus', row)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                        :title="`View ${row.catalog_skus} active catalog SKU(s) in Products`"
                                    >
                                        {{ row.catalog_skus }}
                                    </a>
                                    <span v-else>{{ row.catalog_skus }}</span>
                                </td>
                                <td
                                    class="border-l border-slate-100 px-4 py-3 text-right tabular-nums text-slate-900"
                                >
                                    {{ row.units_received }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {{ row.units_sold }}
                                </td>
                                <td
                                    class="border-l border-slate-100 px-4 py-3 text-right tabular-nums text-slate-900"
                                >
                                    <a
                                        v-if="canDrillDownCount(row.skus_on_hand)"
                                        :href="uniqueSkuCountHref('skus_on_hand', row)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                        :title="`View ${row.skus_on_hand} on-hand SKU(s) in Products`"
                                    >
                                        {{ row.skus_on_hand }}
                                    </a>
                                    <span v-else>{{ row.skus_on_hand }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {{ row.quantity_on_hand }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {{ formatMoney(row.estimated_landed_value) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                    <a
                                        v-if="canDrillDownCount(row.skus_missing_landed_cost)"
                                        :href="
                                            uniqueSkuCountHref('skus_missing_landed_cost', row)
                                        "
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                        :title="`View ${row.skus_missing_landed_cost} on-hand SKU(s) missing landed cost`"
                                    >
                                        {{ row.skus_missing_landed_cost }}
                                    </a>
                                    <span v-else>{{ row.skus_missing_landed_cost }}</span>
                                </td>
                                <td
                                    class="border-l border-slate-100 px-4 py-3 text-right tabular-nums text-slate-900"
                                >
                                    <a
                                        v-if="canDrillDownCount(row.not_arrived_skus)"
                                        :href="
                                            uniqueSkuCountHref('not_arrived_skus', row)
                                        "
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                        :title="`View ${row.not_arrived_skus} SKU(s) with not-arrived PO qty`"
                                    >
                                        {{ row.not_arrived_skus }}
                                    </a>
                                    <span v-else>{{ row.not_arrived_skus }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {{ row.not_arrived }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {{ formatMoney(row.estimated_not_landed_value) }}
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot v-if="totals" class="bg-slate-50">
                        <tr class="font-semibold text-slate-900">
                            <td class="px-4 py-3 text-slate-700">Total</td>
                            <td class="border-l border-slate-200 px-4 py-3 text-right tabular-nums">
                                <a
                                    v-if="canDrillDownCount(totals.catalog_skus)"
                                    :href="uniqueSkuCountHref('catalog_skus', null)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                >
                                    {{ totals.catalog_skus }}
                                </a>
                                <span v-else>{{ totals.catalog_skus }}</span>
                            </td>
                            <td class="border-l border-slate-200 px-4 py-3 text-right tabular-nums">
                                {{ totals.units_received }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ totals.units_sold }}
                            </td>
                            <td class="border-l border-slate-200 px-4 py-3 text-right tabular-nums">
                                <a
                                    v-if="canDrillDownCount(totals.skus_on_hand)"
                                    :href="uniqueSkuCountHref('skus_on_hand', null)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                >
                                    {{ totals.skus_on_hand }}
                                </a>
                                <span v-else>{{ totals.skus_on_hand }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ totals.quantity_on_hand }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatMoney(totals.estimated_landed_value) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <a
                                    v-if="canDrillDownCount(totals.skus_missing_landed_cost)"
                                    :href="uniqueSkuCountHref('skus_missing_landed_cost', null)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                >
                                    {{ totals.skus_missing_landed_cost }}
                                </a>
                                <span v-else>{{ totals.skus_missing_landed_cost }}</span>
                            </td>
                            <td class="border-l border-slate-200 px-4 py-3 text-right tabular-nums">
                                <a
                                    v-if="canDrillDownCount(totals.not_arrived_skus)"
                                    :href="uniqueSkuCountHref('not_arrived_skus', null)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-700 underline decoration-blue-700/30 underline-offset-2 hover:decoration-blue-700"
                                >
                                    {{ totals.not_arrived_skus }}
                                </a>
                                <span v-else>{{ totals.not_arrived_skus }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ totals.not_arrived }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatMoney(totals.estimated_not_landed_value) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import TaxonomyReviewFilters, {
    type CanonicalFilterKey,
    type TaxonomyReviewFilterPayload,
} from '../components/products/TaxonomyReviewFilters.vue';
import TaxonomyBulkUpdateDialog from '../components/products/TaxonomyBulkUpdateDialog.vue';
import TaxonomyVerificationTable from '../components/products/TaxonomyVerificationTable.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import { api } from '../lib/api';
import type {
    TaxonomySummary,
    TaxonomyValues,
    TaxonomyVerification,
} from '../types/productTaxonomy';

const loading = ref(false);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const reviewFilters = ref<TaxonomyReviewFilterPayload>({
    search: '',
    status: 'proposed',
    canonical: {
        department: '',
        manufacturer: '',
        franchise: '',
        product_line: '',
        subline: '',
        grade: '',
        series: '',
        scale: '',
        workshop_shelf: '',
        accessory_kind: '',
    },
    maximumConfidence: '',
    archived: 'all',
    differencesOnly: false,
    missingField: '',
});
const filterOptions = ref<Record<CanonicalFilterKey, string[]>>({
    department: [],
    manufacturer: [],
    franchise: [],
    product_line: [],
    subline: [],
    grade: [],
    series: [],
    scale: [],
    workshop_shelf: [],
    accessory_kind: [],
});
const page = ref(1);
const perPage = 50;
const lastPage = ref(1);
const items = ref<TaxonomyVerification[]>([]);
const summary = ref<TaxonomySummary>({
    total: 0,
    proposed: 0,
    verified: 0,
    overridden: 0,
    low_confidence: 0,
});
const busyId = ref<string | null>(null);
const bulkBusy = ref(false);
const confirmBulkOpen = ref(false);
const bulkUpdateOpen = ref(false);
const bulkUpdateBusy = ref(false);
const selectedIds = ref<string[]>([]);
const exportBusy = ref(false);

const summaryCards = computed(() => [
    { label: 'total researched', value: summary.value.total },
    { label: 'proposed', value: summary.value.proposed },
    { label: 'low confidence', value: summary.value.low_confidence },
    { label: 'verified', value: summary.value.verified },
    { label: 'overridden', value: summary.value.overridden },
]);

async function loadSummary(): Promise<void> {
    const response = await api.get<{ data: TaxonomySummary }>('/api/v1/products/taxonomy/summary');
    summary.value = response.data.data;
}

function verificationParams(
    includePage: boolean,
): Record<string, string | number | string[] | boolean> {
    const params: Record<string, string | number | string[] | boolean> = {};
    if (includePage) {
        params.page = page.value;
        params.per_page = perPage;
    }
    const filters = reviewFilters.value;
    if (filters.search !== '') params.search = filters.search;
    if (filters.status !== '') params.status = filters.status;
    const filterParams: Record<CanonicalFilterKey, string> = {
        department: 'departments',
        manufacturer: 'manufacturers',
        franchise: 'franchises',
        product_line: 'product_lines',
        subline: 'sublines',
        grade: 'grades',
        series: 'series_values',
        scale: 'scales',
        workshop_shelf: 'workshop_shelves',
        accessory_kind: 'accessory_kinds',
    };
    for (const [key, value] of Object.entries(filters.canonical)) {
        if (value !== '') params[filterParams[key as CanonicalFilterKey]] = [value];
    }
    if (filters.maximumConfidence !== '') {
        params.maximum_confidence = Number(filters.maximumConfidence);
    }
    if (filters.archived !== 'all') params.archived = filters.archived;
    if (filters.differencesOnly) params.differences_only = 1;
    if (filters.missingField !== '') params.missing_fields = [filters.missingField];

    return params;
}

async function loadItems(): Promise<void> {
    const params = verificationParams(true);

    const response = await api.get<{
        data: TaxonomyVerification[];
        meta: { last_page: number };
    }>('/api/v1/products/taxonomy/verifications', { params });
    items.value = response.data.data;
    lastPage.value = response.data.meta.last_page;
    const visible = new Set(items.value.map((item) => item.id));
    selectedIds.value = selectedIds.value.filter((id) => visible.has(id));
}

async function loadFilterOptions(): Promise<void> {
    const response = await api.get<{
        data: {
            departments: string[];
            manufacturers: string[];
            franchises: string[];
            product_lines: string[];
            sublines: string[];
            grades: string[];
            series: string[];
            scales: string[];
            workshop_shelves: string[];
            accessory_kinds: string[];
        };
    }>('/api/v1/products/taxonomy/filter-options');
    const data = response.data.data;
    filterOptions.value = {
        department: data.departments,
        manufacturer: data.manufacturers,
        franchise: data.franchises,
        product_line: data.product_lines,
        subline: data.sublines,
        grade: data.grades,
        series: data.series,
        scale: data.scales,
        workshop_shelf: data.workshop_shelves,
        accessory_kind: data.accessory_kinds,
    };
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        await Promise.all([loadSummary(), loadItems(), loadFilterOptions()]);
    } catch {
        error.value = 'Unable to load taxonomy review data.';
    } finally {
        loading.value = false;
    }
}

function applyFilters(filters: TaxonomyReviewFilterPayload): void {
    reviewFilters.value = filters;
    page.value = 1;
    void loadItems();
}

async function approve(
    item: TaxonomyVerification,
    values: TaxonomyValues | null,
    notes: string | null,
): Promise<void> {
    busyId.value = item.id;
    error.value = null;
    try {
        await api.patch(`/api/v1/products/taxonomy/verifications/${item.id}/approve`, {
            values: values ?? {},
            operator: 'local-operator',
            notes,
        });
        notice.value = values
            ? `Saved override for ${item.product.sku}.`
            : `Approved ${item.product.sku}.`;
        await Promise.all([loadSummary(), loadItems()]);
    } catch {
        error.value = `Unable to approve ${item.product.sku}.`;
    } finally {
        busyId.value = null;
    }
}

async function queueResearch(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        await api.post('/api/v1/products/taxonomy/research', {
            research_version: 'canonical-v1.4',
        });
        notice.value = 'Research queued for all products.';
    } catch {
        error.value = 'Unable to queue taxonomy research.';
    } finally {
        loading.value = false;
    }
}

async function confirmBulkApprove(): Promise<void> {
    confirmBulkOpen.value = false;
    bulkBusy.value = true;
    error.value = null;
    try {
        const response = await api.post<{
            data: { approved: number; skipped: number; failed: number };
        }>('/api/v1/products/taxonomy/verifications/bulk-approve', {
            ...verificationParams(false),
            confirm: true,
            operator: 'local-operator',
            minimum_confidence: 90,
            exclude_test_skus: true,
            require_kit_manufacturer: true,
        });
        const result = response.data.data;
        notice.value = `Approved ${result.approved} proposals. Skipped ${result.skipped}. Failed ${result.failed}.`;
        await Promise.all([loadSummary(), loadItems()]);
    } catch {
        error.value = 'Unable to bulk-approve matching proposals.';
    } finally {
        bulkBusy.value = false;
    }
}

async function confirmBulkUpdate(
    values: Partial<TaxonomyValues>,
    notes: string | null,
): Promise<void> {
    bulkUpdateBusy.value = true;
    error.value = null;
    try {
        const response = await api.post<{
            data: { updated: number; skipped: number; failed: number };
        }>('/api/v1/products/taxonomy/verifications/bulk-update', {
            confirm: true,
            operator: 'local-operator',
            notes,
            verification_ids: selectedIds.value,
            values,
        });
        const result = response.data.data;
        notice.value = `Updated ${result.updated} proposals. Skipped ${result.skipped}. Failed ${result.failed}.`;
        bulkUpdateOpen.value = false;
        selectedIds.value = [];
        await Promise.all([loadSummary(), loadItems()]);
    } catch {
        error.value = 'Unable to bulk-update selected proposals.';
    } finally {
        bulkUpdateBusy.value = false;
    }
}

async function exportConfirmationList(): Promise<void> {
    exportBusy.value = true;
    error.value = null;
    try {
        const response = await api.get<Blob>('/api/v1/products/taxonomy/export', {
            params: verificationParams(false),
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'taxonomy-confirmation.csv';
        link.click();
        URL.revokeObjectURL(url);
        notice.value = 'Downloaded the confirmation list.';
    } catch {
        error.value = 'Unable to export the confirmation list.';
    } finally {
        exportBusy.value = false;
    }
}

onMounted(() => {
    void load();
});
</script>

<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-sky-700">Products</p>
                <h1 class="text-3xl font-bold text-slate-950">Taxonomy review</h1>
                <p class="mt-1 max-w-3xl text-sm text-slate-600">
                    Verify evidence-backed canonical fields before Shopify receives additive
                    taxonomy metafields. Existing storefront navigation is unchanged.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    data-testid="taxonomy-export"
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="loading || exportBusy"
                    @click="exportConfirmationList"
                >
                    Export confirmation list
                </button>
                <button
                    data-testid="taxonomy-bulk-update"
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="loading || bulkUpdateBusy || selectedIds.length === 0"
                    @click="bulkUpdateOpen = true"
                >
                    Bulk update{{ selectedIds.length > 0 ? ` (${selectedIds.length})` : '' }}
                </button>
                <button
                    data-testid="taxonomy-bulk-approve"
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="loading || bulkBusy"
                    @click="confirmBulkOpen = true"
                >
                    Approve high-confidence
                </button>
                <button
                    data-testid="taxonomy-research"
                    type="button"
                    class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="queueResearch"
                >
                    Research all products
                </button>
            </div>
        </header>

        <div
            v-if="notice"
            class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            {{ notice }}
        </div>
        <div
            v-if="error"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ error }}
        </div>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <article
                v-for="card in summaryCards"
                :key="card.label"
                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            >
                <p class="text-lg font-bold text-slate-950">{{ card.value }} {{ card.label }}</p>
            </article>
        </section>

        <TaxonomyReviewFilters :options="filterOptions" @apply="applyFilters" />

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
                Loading taxonomy…
            </div>
            <div v-else-if="items.length === 0" class="p-8 text-center text-sm text-slate-500">
                No taxonomy records match these filters.
            </div>
            <TaxonomyVerificationTable
                v-else
                :items="items"
                :busy-id="busyId"
                :selected-ids="selectedIds"
                @approve="approve"
                @update:selected-ids="selectedIds = $event"
            />
        </section>

        <TaxonomyBulkUpdateDialog
            :open="bulkUpdateOpen"
            :selected-count="selectedIds.length"
            :busy="bulkUpdateBusy"
            :options="filterOptions"
            @confirm="confirmBulkUpdate"
            @cancel="bulkUpdateOpen = false"
        />

        <ConfirmDialog
            :open="confirmBulkOpen"
            title="Approve high-confidence proposals?"
            message="This applies matching proposed values to ERP products: model kits at 90%+ confidence (with manufacturer), workshop supplies/paints/tools at 88%+ (with manufacturer or product line). Test SKUs and incomplete kits are skipped."
            confirm-text="Approve matching"
            variant="primary"
            :busy="bulkBusy"
            @confirm="confirmBulkApprove"
            @cancel="confirmBulkOpen = false"
        />

        <footer class="flex items-center justify-between text-sm text-slate-600">
            <button
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5 disabled:opacity-40"
                :disabled="page <= 1"
                @click="
                    page--;
                    loadItems();
                "
            >
                Previous
            </button>
            <span>Page {{ page }} of {{ lastPage }}</span>
            <button
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5 disabled:opacity-40"
                :disabled="page >= lastPage"
                @click="
                    page++;
                    loadItems();
                "
            >
                Next
            </button>
        </footer>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import BulkUpdateDialog from './BulkUpdateDialog.vue';
import BulkExportDialog, { type ProductsBulkExportType } from './BulkExportDialog.vue';
import BulkRecrawlDialog, { type ProductsRecrawlSource } from './BulkRecrawlDialog.vue';
import { formatMoney2 } from '../../lib/money';
import { parseNonNegativeIntOrNull } from '../../lib/numbers';

const FILTER_EMPTY_MAIN_TYPE_TOKEN = '__empty__';

/** Baseline main types (aligned with ProductsPage loadFilterOptions) when API options are missing. */
const DEFAULT_MAIN_TYPE_CHOICES = ['model kit', 'tools', 'paints', 'supplies'] as const;

export type ProductRow = {
    id: string; // uuid
    sku: string;
    barcode: string | null;
    description: string;
    handle: string | null;
    main_type: string;
    type: string | null;
    grade?: string | null;
    series?: string | null;
    scale?: string | null;
    vendor: string | null;
    archived_at?: string | null;
    is_archived?: boolean;
    published_on_shopify?: boolean;
    is_ready?: boolean;
    latest_arrival?: boolean;
    latest_unit_cost?: string | null;
    latest_landed_unit_cost?: string | null;
    /** Latest PO `received_date` (Y-m-d) from any received PO line; null if none. */
    received_date?: string | null;
    selling_price?: string | null;
    pdp?: {
        has_description: boolean;
        plamod_image_count: number;
    };
    total_ordered?: number | null;
    available: number | null;
    maintain: number | null;
    not_arrived?: number | null;
    reorder?: number | null;
};

function isMissingBarcode(p: ProductRow): boolean {
    return !p.barcode || p.barcode.trim() === '';
}

function isMissingSellingPrice(p: ProductRow): boolean {
    return !p.selling_price || String(p.selling_price).trim() === '';
}

function isMissingPdpDescription(p: ProductRow): boolean {
    return !(p.pdp?.has_description ?? false);
}

function isMissingPdpImages(p: ProductRow): boolean {
    return (p.pdp?.plamod_image_count ?? 0) <= 0;
}

export type UpdateProductPayload = {
    sku: string;
    barcode: string | null;
    description: string;
    handle: string | null;
    main_type: string;
    type: string | null;
    grade?: string | null;
    series?: string | null;
    scale?: string | null;
    vendor: string | null;
    available: number | null;
    maintain: number | null;
};

export type BulkUpdateProductChanges = {
    sku?: string;
    barcode?: string | null;
    description?: string;
    handle?: string | null;
    main_type?: string | null;
    type?: string | null;
    grade?: string | null;
    scale?: string | null;
    series?: string | null;
    vendor?: string | null;
    published_on_shopify?: boolean;
    latest_arrival?: boolean;
    available?: number | null;
    maintain?: number | null;
    archived?: boolean;
};

export type ProductSortKey =
    | 'sku'
    | 'barcode'
    | 'description'
    | 'main_type'
    | 'type'
    | 'grade'
    | 'series'
    | 'scale'
    | 'vendor'
    | 'latest_landed_unit_cost'
    | 'received_date'
    | 'selling_price'
    | 'total_ordered'
    | 'total_sold'
    | 'available'
    | 'maintain'
    | 'not_arrived'
    | 'reorder';

const props = defineProps<{
    loading: boolean;
    products: ProductRow[];
    totalMatching: number;
    selectionScopeKey: string;
    sortBy: ProductSortKey;
    sortDir: 'asc' | 'desc';
    onSortChange: (sortBy: ProductSortKey) => void;
    onRefresh: () => Promise<void>;
    onBulkDelete: (ids: string[]) => Promise<number>;
    onBulkArchive: (ids: string[]) => Promise<number>;
    onBulkUpdate: (ids: string[], changes: BulkUpdateProductChanges) => Promise<number>;
    onBulkRenamePlamodAssets: (ids: string[]) => Promise<{ queued: number; batchId: string }>;
    onBulkExportSelected: (ids: string[], exportType: ProductsBulkExportType) => Promise<void>;
    onBulkRecrawlSelected: (ids: string[], sources: ProductsRecrawlSource[]) => Promise<void>;
    onUpdate: (id: string, payload: UpdateProductPayload) => Promise<void>;
    onUpdateAvailable: (id: string, available: number | null) => Promise<void>;
    onUpdateMaintain: (id: string, maintain: number | null) => Promise<void>;
    onToggleReady: (id: string, isReady: boolean) => Promise<void>;
    onToggleLatestArrival: (id: string, latestArrival: boolean) => Promise<void>;
    onSelectAllMatching: () => Promise<string[]>;
    onOpenPlamod: (id: string) => void;
    onOpenPoLines: (id: string) => void;
    vendorOptions?: string[];
    mainTypeOptions?: string[];
    typeOptions?: string[];
    gradeOptions?: string[];
    scaleOptions?: string[];
    seriesOptions?: string[];
}>();

const vendorChoices = computed<string[]>(() => {
    const base = (props.vendorOptions ?? []).map((v) => v.trim()).filter((v) => v !== '');
    const current = draft.value?.vendor?.trim() ?? '';
    const merged = current !== '' ? [...base, current] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
});

const mainTypeChoices = computed<string[]>(() => {
    const fromProps = (props.mainTypeOptions ?? [])
        .map((v) => String(v ?? '').trim())
        .filter((v) => v !== '' && v.toLowerCase() !== FILTER_EMPTY_MAIN_TYPE_TOKEN);
    const base = Array.from(new Set([...DEFAULT_MAIN_TYPE_CHOICES, ...fromProps]));
    const current = draft.value?.main_type?.trim() ?? '';
    const merged = current !== '' ? [...base, current] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
});

const typeChoices = computed<string[]>(() => {
    const base = (props.typeOptions ?? [])
        .map((v) => String(v ?? '').trim())
        .filter((v) => v !== '');
    const current = draft.value?.type?.trim() ?? '';
    const merged = current !== '' ? [...base, current] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
});

function mergeDistinctOptions(
    propList: string[] | undefined,
    current: string | null | undefined,
): string[] {
    const base = (propList ?? []).map((v) => String(v ?? '').trim()).filter((v) => v !== '');
    const cur = (current ?? '').trim();
    const merged = cur !== '' ? [...base, cur] : base;
    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
}

const gradeChoices = computed<string[]>(() =>
    mergeDistinctOptions(props.gradeOptions, draft.value?.grade ?? null),
);

const scaleChoices = computed<string[]>(() =>
    mergeDistinctOptions(props.scaleOptions, draft.value?.scale ?? null),
);

const seriesChoices = computed<string[]>(() =>
    mergeDistinctOptions(props.seriesOptions, draft.value?.series ?? null),
);

function setDraftTypeFromSelect(event: Event): void {
    if (!draft.value) {
        return;
    }
    const v = (event.target as HTMLSelectElement).value;
    draft.value.type = v === '' ? null : v;
}

const selected = ref<Set<string>>(new Set());
const allMatchingSelected = ref(false);
const selectingAllMatching = ref(false);
const bulkDeleting = ref(false);
const bulkArchiving = ref(false);
const bulkUpdating = ref(false);
const bulkExporting = ref(false);
const showCost = ref(false);
const showClassificationColumns = ref(true);
const bulkMessage = ref<string | null>(null);
const bulkError = ref<string | null>(null);
const confirmBulkDeleteOpen = ref(false);
const confirmBulkDeleteCount = ref(0);
const confirmBulkArchiveOpen = ref(false);
const confirmBulkArchiveCount = ref(0);
const bulkUpdateOpen = ref(false);
const bulkExportOpen = ref(false);
const bulkRecrawlOpen = ref(false);

const editingId = ref<string | null>(null);
const draft = ref<UpdateProductPayload | null>(null);
const saving = ref(false);
const rowError = ref<string | null>(null);
const togglingReady = ref<Record<string, true>>({});
const togglingLatestArrival = ref<Record<string, true>>({});

const savingAvailableId = ref<string | null>(null);
const savingMaintainId = ref<string | null>(null);
const availableDrafts = ref<Record<string, string>>({});
const maintainDrafts = ref<Record<string, string>>({});

function isSavingAvailable(id: string): boolean {
    return savingAvailableId.value === id;
}

function isSavingMaintain(id: string): boolean {
    return savingMaintainId.value === id;
}

function startAvailableInlineEdit(productId: string, current: number | null): void {
    if (availableDrafts.value[productId] === undefined) {
        availableDrafts.value = {
            ...availableDrafts.value,
            [productId]: current === null ? '' : String(current),
        };
    }
}

function updateAvailableDraft(productId: string, value: string): void {
    availableDrafts.value = { ...availableDrafts.value, [productId]: value };
}

function commitAvailableInlineEdit(productId: string): void {
    if (isSavingAvailable(productId)) return;
    const value = availableDrafts.value[productId] ?? '';
    const { [productId]: _omit, ...rest } = availableDrafts.value;
    availableDrafts.value = rest;
    void saveAvailableInline(productId, value);
}

async function saveAvailableInline(productId: string, value: string | null): Promise<void> {
    savingAvailableId.value = productId;
    rowError.value = null;

    try {
        const available = parseNonNegativeIntOrNull(value ?? '');
        await props.onUpdateAvailable(productId, available);
    } catch (e: unknown) {
        rowError.value = formatBulkError(e, 'Failed to save available quantity.');
    } finally {
        if (savingAvailableId.value === productId) {
            savingAvailableId.value = null;
        }
    }
}

function startMaintainInlineEdit(productId: string, current: number | null): void {
    if (maintainDrafts.value[productId] === undefined) {
        maintainDrafts.value = {
            ...maintainDrafts.value,
            [productId]: current === null ? '' : String(current),
        };
    }
}

function updateMaintainDraft(productId: string, value: string): void {
    maintainDrafts.value = { ...maintainDrafts.value, [productId]: value };
}

function commitMaintainInlineEdit(productId: string): void {
    if (isSavingMaintain(productId)) return;
    const value = maintainDrafts.value[productId] ?? '';
    const { [productId]: _omit, ...rest } = maintainDrafts.value;
    maintainDrafts.value = rest;
    void saveMaintainInline(productId, value);
}

async function saveMaintainInline(productId: string, value: string | null): Promise<void> {
    savingMaintainId.value = productId;
    rowError.value = null;

    try {
        const maintain = parseNonNegativeIntOrNull(value ?? '');
        await props.onUpdateMaintain(productId, maintain);
    } catch (e: unknown) {
        rowError.value = formatBulkError(e, 'Failed to save maintain quantity.');
    } finally {
        if (savingMaintainId.value === productId) {
            savingMaintainId.value = null;
        }
    }
}

function isTogglingReady(id: string): boolean {
    return togglingReady.value[id] === true;
}

function isTogglingLatestArrival(id: string): boolean {
    return togglingLatestArrival.value[id] === true;
}

async function toggleReady(id: string, isReady: boolean): Promise<void> {
    if (isTogglingReady(id)) return;
    togglingReady.value = { ...togglingReady.value, [id]: true };
    try {
        await props.onToggleReady(id, isReady);
    } catch (e: unknown) {
        rowError.value = formatBulkError(e, 'Failed to update ready flag.');
    } finally {
        const { [id]: _omit, ...rest } = togglingReady.value;
        togglingReady.value = rest;
    }
}

async function toggleLatestArrival(id: string, latestArrival: boolean): Promise<void> {
    if (isTogglingLatestArrival(id)) return;
    togglingLatestArrival.value = { ...togglingLatestArrival.value, [id]: true };
    try {
        await props.onToggleLatestArrival(id, latestArrival);
    } catch (e: unknown) {
        rowError.value = formatBulkError(e, 'Failed to update latest arrival flag.');
    } finally {
        const { [id]: _omit, ...rest } = togglingLatestArrival.value;
        togglingLatestArrival.value = rest;
    }
}

function formatBulkError(e: unknown, fallback: string): string {
    const anyErr = e as any;
    const status: unknown = anyErr?.response?.status;
    const statusNum = typeof status === 'number' ? status : null;

    const data = anyErr?.response?.data;
    const msg = data?.message ?? data?.error ?? anyErr?.message ?? null;
    const msgStr =
        typeof msg === 'string'
            ? msg.trim()
            : msg !== null && msg !== undefined
              ? String(msg).trim()
              : '';

    if (statusNum !== null) {
        return msgStr !== ''
            ? `${fallback} (HTTP ${statusNum}). ${msgStr}`
            : `${fallback} (HTTP ${statusNum}).`;
    }
    return msgStr !== '' ? `${fallback} ${msgStr}` : fallback;
}

const allSelected = computed(
    () => props.products.length > 0 && props.products.every((p) => selected.value.has(p.id)),
);

const allOnPageSelected = computed(
    () => props.products.length > 0 && props.products.every((p) => selected.value.has(p.id)),
);

const emptyRowColspan = computed(() => {
    const total = showCost.value ? 22 : 21;
    return showClassificationColumns.value ? total : total - 6;
});

function totalSold(p: ProductRow): number {
    return Number(p.total_ordered ?? 0) - Number(p.available ?? 0);
}

function formatReceivedDate(iso: string | null | undefined): string {
    if (!iso || String(iso).trim() === '') return '—';
    const d = new Date(`${String(iso).trim()}T12:00:00`);
    return Number.isNaN(d.getTime())
        ? String(iso)
        : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function reorderValue(p: ProductRow): number {
    const maintain = Number(p.maintain ?? 0);
    const available = Number(p.available ?? 0);
    const notArrived = Number(p.not_arrived ?? 0);
    const next = maintain - available - notArrived;
    return next > 0 ? next : 0;
}

function sortLabel(key: ProductSortKey): string {
    const map: Record<ProductSortKey, string> = {
        sku: 'SKU',
        barcode: 'Barcode',
        description: 'Name',
        main_type: 'Main type',
        type: 'Type',
        grade: 'Grade',
        series: 'Series',
        scale: 'Scale',
        vendor: 'Vendor',
        latest_landed_unit_cost: 'Cost (Latest)',
        received_date: 'Received',
        selling_price: 'Selling price',
        total_ordered: 'Total ordered',
        total_sold: 'Total sold',
        available: 'Available',
        maintain: 'Maintain',
        not_arrived: 'Not arrived',
        reorder: 'Reorder',
    };
    return map[key];
}

function sortIndicator(key: ProductSortKey): string {
    if (props.sortBy !== key) return '';
    return props.sortDir === 'asc' ? ' ▲' : ' ▼';
}

function sortHeaderClass(key: ProductSortKey): string {
    return props.sortBy === key ? 'text-slate-900' : 'text-slate-600';
}

function syncSelection(): void {
    if (allMatchingSelected.value) return;
    const allowed = new Set(props.products.map((p) => p.id));
    selected.value = new Set(Array.from(selected.value).filter((id) => allowed.has(id)));
}

watch(
    () => props.products,
    (next) => {
        const allowed = new Set(next.map((p) => p.id));
        availableDrafts.value = Object.fromEntries(
            Object.entries(availableDrafts.value).filter(([id]) => allowed.has(id)),
        );
        maintainDrafts.value = Object.fromEntries(
            Object.entries(maintainDrafts.value).filter(([id]) => allowed.has(id)),
        );
    },
);

function toggleAll(checked: boolean): void {
    if (checked) {
        selected.value = new Set(props.products.map((p) => p.id));
        allMatchingSelected.value = false;
        return;
    }
    selected.value = new Set();
    allMatchingSelected.value = false;
}

function toggleOne(id: string, checked: boolean): void {
    const next = new Set(selected.value);
    if (checked) next.add(id);
    else next.delete(id);
    selected.value = next;
    if (!checked) {
        allMatchingSelected.value = false;
    }
}

async function selectAllMatching(): Promise<void> {
    if (selectingAllMatching.value) return;
    bulkError.value = null;
    bulkMessage.value = null;
    rowError.value = null;

    selectingAllMatching.value = true;
    try {
        const ids = await props.onSelectAllMatching();
        selected.value = new Set(ids);
        allMatchingSelected.value = true;
        bulkMessage.value = `Selected ${ids.length} product(s).`;
    } catch (e: unknown) {
        allMatchingSelected.value = false;
        bulkError.value = formatBulkError(e, 'Failed to select all matching products.');
    } finally {
        selectingAllMatching.value = false;
    }
}

watch(
    () => props.selectionScopeKey,
    () => {
        selected.value = new Set();
        allMatchingSelected.value = false;
        selectingAllMatching.value = false;
        bulkError.value = null;
        bulkMessage.value = null;
        rowError.value = null;
    },
);

function requestBulkDelete(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    confirmBulkDeleteCount.value = ids.length;
    confirmBulkDeleteOpen.value = true;
}

function requestBulkArchive(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    confirmBulkArchiveCount.value = ids.length;
    confirmBulkArchiveOpen.value = true;
}

function requestBulkUpdate(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkUpdateOpen.value = true;
}

function requestBulkExport(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkExportOpen.value = true;
}

function requestBulkRecrawl(): void {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkRecrawlOpen.value = true;
}

async function confirmBulkDelete(): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkDeleting.value = true;

    try {
        const deleted = await props.onBulkDelete(ids);
        bulkMessage.value = `Deleted ${deleted} product(s).`;
        selected.value = new Set();
        allMatchingSelected.value = false;
        await props.onRefresh();
        syncSelection();
    } catch (e: unknown) {
        bulkError.value = formatBulkError(e, 'Failed to delete selected products.');
    } finally {
        bulkDeleting.value = false;
        confirmBulkDeleteOpen.value = false;
    }
}

async function confirmBulkArchive(): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkArchiving.value = true;
    try {
        const updated = await props.onBulkArchive(ids);
        bulkMessage.value = `Archived ${updated} product(s).`;
        // Keep selection so user can immediately export selected (Status=archived).
    } catch (e: unknown) {
        bulkError.value = formatBulkError(e, 'Failed to archive selected products.');
    } finally {
        bulkArchiving.value = false;
        confirmBulkArchiveOpen.value = false;
    }
}

async function confirmBulkUpdate(payload: {
    changes: BulkUpdateProductChanges;
    renamePlamodAssets: boolean;
}): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkUpdating.value = true;
    try {
        const changes = payload.changes;
        const doRename = payload.renamePlamodAssets;

        let parts: string[] = [];
        if (Object.keys(changes).length > 0) {
            const updated = await props.onBulkUpdate(ids, changes);
            parts.push(`Updated ${updated} product(s).`);
        }
        if (doRename) {
            const out = await props.onBulkRenamePlamodAssets(ids);
            const short = out.batchId ? out.batchId.slice(0, 8) : '';
            parts.push(
                `Queued rename for ${out.queued} product(s).${short ? ` Batch ${short}.` : ''} Open Sync Progress to monitor.`,
            );
        }
        if (parts.length === 0) {
            parts = ['No changes selected.'];
        }
        bulkMessage.value = parts.join(' ');
        await props.onRefresh();
        syncSelection();
        bulkUpdateOpen.value = false;
        allMatchingSelected.value = false;
    } catch (e: unknown) {
        bulkError.value = formatBulkError(e, 'Failed to update selected products.');
    } finally {
        bulkUpdating.value = false;
    }
}

async function confirmBulkExport(payload: { exportType: ProductsBulkExportType }): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    bulkExporting.value = true;
    try {
        await props.onBulkExportSelected(ids, payload.exportType);
        bulkMessage.value =
            payload.exportType === 'shopify_content_rename_export'
                ? 'Queued rename + export. Open Sync Progress to monitor; the download will start when renaming finishes.'
                : 'Export started.';
        bulkExportOpen.value = false;
    } catch (e: unknown) {
        bulkError.value = formatBulkError(e, 'Failed to export selected products.');
    } finally {
        bulkExporting.value = false;
    }
}

async function confirmBulkRecrawl(payload: { sources: ProductsRecrawlSource[] }): Promise<void> {
    bulkError.value = null;
    bulkMessage.value = null;

    const ids = Array.from(selected.value);
    if (ids.length === 0) {
        bulkError.value = 'No products selected.';
        return;
    }

    const sources = payload.sources;
    if (sources.length === 0) {
        bulkError.value = 'Pick at least one recrawl source.';
        return;
    }

    bulkExporting.value = true;
    try {
        await props.onBulkRecrawlSelected(ids, sources);
        bulkMessage.value = 'Recrawl queued. Open Sync Progress to watch it.';
        bulkRecrawlOpen.value = false;
    } catch (e: unknown) {
        const anyErr = e as any;
        const msg = typeof anyErr?.message === 'string' ? anyErr.message.trim() : '';
        bulkError.value = msg !== '' ? msg : 'Failed to queue recrawl.';
    } finally {
        bulkExporting.value = false;
    }
}

function startEdit(p: ProductRow): void {
    rowError.value = null;
    editingId.value = p.id;
    draft.value = {
        sku: p.sku,
        barcode: p.barcode,
        description: p.description,
        handle: p.handle,
        main_type: p.main_type,
        type: p.type,
        grade: p.grade ?? null,
        scale: p.scale ?? null,
        series: p.series ?? null,
        vendor: p.vendor,
        available: p.available,
        maintain: p.maintain,
    };
}

function cancelEdit(): void {
    editingId.value = null;
    draft.value = null;
    rowError.value = null;
}

async function saveEdit(): Promise<void> {
    if (!editingId.value || !draft.value) return;

    rowError.value = null;
    if (!draft.value.sku.trim() || !draft.value.description.trim()) {
        rowError.value = 'SKU and Name are required.';
        return;
    }

    saving.value = true;
    try {
        await props.onUpdate(editingId.value, {
            sku: draft.value.sku.trim(),
            barcode: draft.value.barcode?.trim() || null,
            description: draft.value.description.trim(),
            handle: draft.value.handle?.trim() || null,
            main_type: draft.value.main_type.trim() || 'model kit',
            type: draft.value.type?.trim() || null,
            grade: draft.value.grade?.trim() || null,
            scale: draft.value.scale?.trim() || null,
            series: draft.value.series?.trim() || null,
            vendor: draft.value.vendor?.trim() || null,
            available: draft.value.available,
            maintain: draft.value.maintain,
        });
        await props.onRefresh();
        cancelEdit();
    } catch (e: unknown) {
        rowError.value = 'Failed to save changes (check SKU uniqueness and required fields).';
    } finally {
        saving.value = false;
    }
}

function onDocumentKeydown(e: KeyboardEvent): void {
    if (editingId.value === null) {
        return;
    }
    if (e.key === 'Escape') {
        e.preventDefault();
        cancelEdit();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        void saveEdit();
    }
}

onMounted(() => {
    document.addEventListener('keydown', onDocumentKeydown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', onDocumentKeydown);
});
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>

        <div v-else class="overflow-x-auto">
            <div
                v-if="selected.size > 0"
                class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm"
            >
                <div class="text-slate-700">
                    <span class="font-semibold">{{ selected.size }}</span> selected
                    <span v-if="allMatchingSelected" class="text-slate-500"> · all pages</span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkArchiving || bulkUpdating || bulkExporting"
                        @click="
                            selected = new Set();
                            allMatchingSelected = false;
                        "
                    >
                        Clear
                    </button>
                    <button
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkArchiving || bulkUpdating || bulkExporting"
                        @click="requestBulkUpdate"
                    >
                        {{ bulkUpdating ? 'Updating…' : 'Update selected' }}
                    </button>
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkArchiving || bulkUpdating || bulkExporting"
                        @click="requestBulkExport"
                    >
                        {{ bulkExporting ? 'Exporting…' : 'Export selected' }}
                    </button>
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkArchiving || bulkUpdating || bulkExporting"
                        @click="requestBulkRecrawl"
                    >
                        Recrawl selected
                    </button>
                    <button
                        class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-amber-700 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkArchiving || bulkUpdating || bulkExporting"
                        @click="requestBulkArchive"
                    >
                        {{ bulkArchiving ? 'Archiving…' : 'Archive selected' }}
                    </button>
                    <button
                        class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-rose-700 disabled:opacity-50"
                        type="button"
                        :disabled="bulkDeleting || bulkArchiving || bulkUpdating || bulkExporting"
                        @click="requestBulkDelete"
                    >
                        {{ bulkDeleting ? 'Deleting…' : 'Delete selected' }}
                    </button>
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                        type="button"
                        data-testid="toggle-cost-visibility"
                        @click="showCost = !showCost"
                    >
                        {{ showCost ? 'Hide cost' : 'Show cost' }}
                    </button>
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                        type="button"
                        data-testid="toggle-classification-visibility"
                        @click="showClassificationColumns = !showClassificationColumns"
                    >
                        {{
                            showClassificationColumns
                                ? 'Hide type/grade/scale'
                                : 'Show type/grade/scale'
                        }}
                    </button>
                </div>
            </div>
            <div
                v-else
                class="flex items-center justify-end border-b border-slate-200 bg-white px-4 py-2"
            >
                <button
                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                    type="button"
                    data-testid="toggle-cost-visibility"
                    @click="showCost = !showCost"
                >
                    {{ showCost ? 'Hide cost' : 'Show cost' }}
                </button>
                <button
                    class="ml-2 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
                    type="button"
                    data-testid="toggle-classification-visibility"
                    @click="showClassificationColumns = !showClassificationColumns"
                >
                    {{
                        showClassificationColumns
                            ? 'Hide type/grade/scale'
                            : 'Show type/grade/scale'
                    }}
                </button>
            </div>

            <div
                v-if="allOnPageSelected && !allMatchingSelected && totalMatching > products.length"
                class="border-b border-slate-200 bg-white px-4 py-2 text-sm text-slate-700"
            >
                All <span class="font-semibold">{{ products.length }}</span> products on this page
                are selected.
                <button
                    type="button"
                    class="ml-2 font-semibold text-slate-900 underline underline-offset-2 disabled:opacity-50"
                    :disabled="selectingAllMatching"
                    @click="selectAllMatching"
                >
                    {{
                        selectingAllMatching ? 'Selecting…' : `Select all ${totalMatching} products`
                    }}
                </button>
            </div>

            <div
                v-if="bulkError"
                class="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ bulkError }}
            </div>
            <div
                v-if="bulkMessage"
                class="m-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
            >
                {{ bulkMessage }}
            </div>
            <div
                v-if="rowError"
                class="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
            >
                {{ rowError }}
            </div>

            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-xs font-semibold tracking-wide text-slate-600">
                        <th class="w-12 px-4 py-3">
                            <input
                                class="h-4 w-4 rounded border-slate-300"
                                type="checkbox"
                                :checked="allSelected"
                                :disabled="products.length === 0"
                                @change="toggleAll(($event.target as HTMLInputElement).checked)"
                            />
                        </th>
                        <th class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('description')"
                                @click="onSortChange('description')"
                            >
                                Product{{ sortIndicator('description') }}
                            </button>
                        </th>
                        <th v-if="showClassificationColumns" class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('main_type')"
                                @click="onSortChange('main_type')"
                            >
                                {{ sortLabel('main_type') }}{{ sortIndicator('main_type') }}
                            </button>
                        </th>
                        <th v-if="showClassificationColumns" class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('type')"
                                @click="onSortChange('type')"
                            >
                                {{ sortLabel('type') }}{{ sortIndicator('type') }}
                            </button>
                        </th>
                        <th v-if="showClassificationColumns" class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('grade')"
                                @click="onSortChange('grade')"
                            >
                                {{ sortLabel('grade') }}{{ sortIndicator('grade') }}
                            </button>
                        </th>
                        <th v-if="showClassificationColumns" class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('scale')"
                                @click="onSortChange('scale')"
                            >
                                {{ sortLabel('scale') }}{{ sortIndicator('scale') }}
                            </button>
                        </th>
                        <th v-if="showClassificationColumns" class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('series')"
                                @click="onSortChange('series')"
                            >
                                {{ sortLabel('series') }}{{ sortIndicator('series') }}
                            </button>
                        </th>
                        <th class="px-4 py-3">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('vendor')"
                                @click="onSortChange('vendor')"
                            >
                                {{ sortLabel('vendor') }}{{ sortIndicator('vendor') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 whitespace-nowrap">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('received_date')"
                                @click="onSortChange('received_date')"
                            >
                                {{ sortLabel('received_date') }}{{ sortIndicator('received_date') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('selling_price')"
                                @click="onSortChange('selling_price')"
                            >
                                {{ sortLabel('selling_price') }}{{ sortIndicator('selling_price') }}
                            </button>
                        </th>
                        <th v-if="showCost" class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('latest_landed_unit_cost')"
                                @click="onSortChange('latest_landed_unit_cost')"
                            >
                                {{ sortLabel('latest_landed_unit_cost')
                                }}{{ sortIndicator('latest_landed_unit_cost') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('total_ordered')"
                                data-testid="products-sort-total-ordered"
                                @click="onSortChange('total_ordered')"
                            >
                                {{ sortLabel('total_ordered') }}{{ sortIndicator('total_ordered') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('total_sold')"
                                data-testid="products-sort-total-sold"
                                @click="onSortChange('total_sold')"
                            >
                                {{ sortLabel('total_sold') }}{{ sortIndicator('total_sold') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('available')"
                                @click="onSortChange('available')"
                            >
                                {{ sortLabel('available') }}{{ sortIndicator('available') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('maintain')"
                                @click="onSortChange('maintain')"
                            >
                                {{ sortLabel('maintain') }}{{ sortIndicator('maintain') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('not_arrived')"
                                data-testid="products-sort-not-arrived"
                                @click="onSortChange('not_arrived')"
                            >
                                {{ sortLabel('not_arrived') }}{{ sortIndicator('not_arrived') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="hover:underline"
                                :class="sortHeaderClass('reorder')"
                                data-testid="products-sort-reorder"
                                @click="onSortChange('reorder')"
                            >
                                {{ sortLabel('reorder') }}{{ sortIndicator('reorder') }}
                            </button>
                        </th>
                        <th class="px-4 py-3">Info</th>
                        <th class="px-4 py-3">Ready</th>
                        <th class="px-4 py-3">Latest arrival</th>
                        <th class="px-4 py-3">Published on Shopify</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="products.length === 0">
                        <td class="px-4 py-4 text-slate-600" :colspan="emptyRowColspan">
                            No products yet. Import a CSV or add one manually above.
                        </td>
                    </tr>

                    <tr v-for="p in products" :key="p.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <input
                                class="h-4 w-4 rounded border-slate-300"
                                type="checkbox"
                                :checked="selected.has(p.id)"
                                @change="
                                    toggleOne(p.id, ($event.target as HTMLInputElement).checked)
                                "
                            />
                        </td>

                        <td class="px-4 py-3">
                            <template v-if="editingId === p.id">
                                <div class="flex flex-col gap-2">
                                    <input
                                        v-model="draft!.description"
                                        class="w-[28rem] max-w-full rounded-md border border-slate-200 px-2 py-1 text-sm"
                                        type="text"
                                    />
                                    <div class="flex flex-wrap gap-2">
                                        <input
                                            v-model="draft!.sku"
                                            class="w-40 rounded-md border border-slate-200 px-2 py-1 font-mono text-xs"
                                            type="text"
                                        />
                                        <input
                                            v-model="draft!.barcode"
                                            class="w-44 rounded-md border border-slate-200 px-2 py-1 font-mono text-xs"
                                            type="text"
                                        />
                                    </div>
                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <button
                                            class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50"
                                            type="button"
                                            :disabled="saving"
                                            @click="saveEdit"
                                        >
                                            {{ saving ? 'Saving…' : 'Save' }}
                                        </button>
                                        <button
                                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                                            type="button"
                                            :disabled="saving"
                                            @click="cancelEdit"
                                        >
                                            Cancel
                                        </button>
                                        <span class="self-center text-[11px] text-slate-500">
                                            Esc cancel · Ctrl+Enter save
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <div
                                    class="cursor-pointer rounded-md px-1 py-0.5 transition hover:bg-slate-100"
                                    role="button"
                                    tabindex="0"
                                    title="Click to edit"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                >
                                    <div
                                        class="max-w-[28rem] truncate font-medium text-slate-900"
                                        :title="p.description"
                                    >
                                        {{ p.description }}
                                    </div>
                                    <div
                                        class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-slate-600"
                                    >
                                        <span class="font-mono">{{ p.sku }}</span>
                                        <span class="font-mono">{{ p.barcode ?? '—' }}</span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        <span
                                            class="font-mono"
                                            :class="
                                                p.handle && p.handle.trim() !== ''
                                                    ? 'text-slate-600'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                p.handle && p.handle.trim() !== '' ? p.handle : '—'
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </template>
                        </td>

                        <td v-if="showClassificationColumns" class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    v-model="draft!.main_type"
                                    class="w-36 max-w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                                >
                                    <option
                                        v-for="opt in mainTypeChoices"
                                        :key="`main-type-${opt}`"
                                        :value="opt"
                                    >
                                        {{ opt }}
                                    </option>
                                </select>
                            </template>
                            <template v-else>
                                <div
                                    class="max-w-[10rem] cursor-pointer truncate rounded-md px-1 py-0.5 transition hover:bg-slate-100"
                                    title="Click to edit"
                                    role="button"
                                    tabindex="0"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                >
                                    {{ p.main_type }}
                                </div>
                            </template>
                        </td>

                        <td v-if="showClassificationColumns" class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    class="max-w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                                    :class="typeChoices.length > 4 ? 'w-44' : 'w-28'"
                                    :value="draft!.type ?? ''"
                                    @change="setDraftTypeFromSelect"
                                >
                                    <option value="">—</option>
                                    <option
                                        v-for="opt in typeChoices"
                                        :key="`type-${opt}`"
                                        :value="opt"
                                    >
                                        {{ opt }}
                                    </option>
                                </select>
                            </template>
                            <template v-else>
                                <span
                                    class="inline-block cursor-pointer rounded-md px-1 py-0.5 transition hover:bg-slate-100"
                                    title="Click to edit"
                                    role="button"
                                    tabindex="0"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                    >{{ p.type ?? '—' }}</span
                                >
                            </template>
                        </td>

                        <td v-if="showClassificationColumns" class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    v-model="draft!.grade"
                                    class="w-24 max-w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                                >
                                    <option :value="null">—</option>
                                    <option
                                        v-for="opt in gradeChoices"
                                        :key="`grade-${opt}`"
                                        :value="opt"
                                    >
                                        {{ opt }}
                                    </option>
                                </select>
                            </template>
                            <template v-else>
                                <span
                                    class="inline-block cursor-pointer rounded-md px-1 py-0.5 font-semibold text-slate-900 transition hover:bg-slate-100"
                                    title="Click to edit"
                                    role="button"
                                    tabindex="0"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                    >{{ p.grade ?? '—' }}</span
                                >
                            </template>
                        </td>

                        <td v-if="showClassificationColumns" class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    v-model="draft!.scale"
                                    class="w-24 max-w-full rounded-md border border-slate-200 bg-white px-2 py-1 font-mono text-xs"
                                >
                                    <option :value="null">—</option>
                                    <option
                                        v-for="opt in scaleChoices"
                                        :key="`scale-${opt}`"
                                        :value="opt"
                                    >
                                        {{ opt }}
                                    </option>
                                </select>
                            </template>
                            <template v-else>
                                <span
                                    class="inline-block cursor-pointer rounded-md px-1 py-0.5 font-mono text-xs transition hover:bg-slate-100"
                                    title="Click to edit"
                                    role="button"
                                    tabindex="0"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                    >{{ p.scale ?? '—' }}</span
                                >
                            </template>
                        </td>

                        <td v-if="showClassificationColumns" class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    v-model="draft!.series"
                                    class="w-56 max-w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                                >
                                    <option :value="null">—</option>
                                    <option
                                        v-for="opt in seriesChoices"
                                        :key="`series-${opt}`"
                                        :value="opt"
                                    >
                                        {{ opt }}
                                    </option>
                                </select>
                            </template>
                            <template v-else>
                                <div
                                    class="max-w-[16rem] cursor-pointer truncate rounded-md px-1 py-0.5 transition hover:bg-slate-100"
                                    :title="(p.series ?? '') || 'Click to edit'"
                                    role="button"
                                    tabindex="0"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                >
                                    {{ p.series ?? '—' }}
                                </div>
                            </template>
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <template v-if="editingId === p.id">
                                <select
                                    v-model="draft!.vendor"
                                    class="w-36 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm"
                                >
                                    <option :value="null">—</option>
                                    <option v-for="v in vendorChoices" :key="v" :value="v">
                                        {{ v }}
                                    </option>
                                </select>
                            </template>
                            <template v-else>
                                <span
                                    class="inline-block cursor-pointer rounded-md px-1 py-0.5 transition hover:bg-slate-100"
                                    title="Click to edit"
                                    role="button"
                                    tabindex="0"
                                    @click="startEdit(p)"
                                    @keydown.enter.prevent="startEdit(p)"
                                    >{{ p.vendor ?? '—' }}</span
                                >
                            </template>
                        </td>

                        <td class="px-4 py-3 whitespace-nowrap text-slate-700">
                            {{ formatReceivedDate(p.received_date) }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            {{ p.selling_price ? formatMoney2(p.selling_price) : '—' }}
                        </td>

                        <td
                            v-if="showCost"
                            class="px-4 py-3 text-right tabular-nums text-slate-700"
                        >
                            {{
                                p.latest_landed_unit_cost
                                    ? formatMoney2(p.latest_landed_unit_cost)
                                    : '—'
                            }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            {{ Math.max(0, Number(p.total_ordered ?? 0)) }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            {{ totalSold(p) }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model.number="draft!.available"
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                />
                            </template>
                            <template v-else>
                                <input
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    :disabled="isSavingAvailable(p.id)"
                                    :value="
                                        availableDrafts[p.id] ??
                                        (p.available === null ? '' : String(p.available))
                                    "
                                    :data-testid="`product-available-input:${p.id}`"
                                    @focus="startAvailableInlineEdit(p.id, p.available)"
                                    @input="
                                        updateAvailableDraft(
                                            p.id,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                    @keydown.enter.prevent="commitAvailableInlineEdit(p.id)"
                                    @blur="commitAvailableInlineEdit(p.id)"
                                />
                            </template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            <template v-if="editingId === p.id">
                                <input
                                    v-model.number="draft!.maintain"
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                />
                            </template>
                            <template v-else>
                                <input
                                    class="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm text-right"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    :disabled="isSavingMaintain(p.id)"
                                    :value="
                                        maintainDrafts[p.id] ??
                                        (p.maintain === null ? '' : String(p.maintain))
                                    "
                                    :data-testid="`product-maintain-input:${p.id}`"
                                    @focus="startMaintainInlineEdit(p.id, p.maintain)"
                                    @input="
                                        updateMaintainDraft(
                                            p.id,
                                            ($event.target as HTMLInputElement).value,
                                        )
                                    "
                                    @keydown.enter.prevent="commitMaintainInlineEdit(p.id)"
                                    @blur="commitMaintainInlineEdit(p.id)"
                                />
                            </template>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            {{ Math.max(0, Number(p.not_arrived ?? 0)) }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900">
                            <span :data-testid="`product-reorder-value:${p.id}`">
                                {{ reorderValue(p) }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            <button
                                v-if="editingId !== p.id"
                                class="flex cursor-pointer flex-wrap gap-1 rounded-md text-left transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300"
                                type="button"
                                data-testid="product-info-open"
                                :aria-label="`Open PDP info for ${p.sku}`"
                                @click="props.onOpenPlamod(p.id)"
                            >
                                <span
                                    v-if="isMissingPdpImages(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    images
                                </span>
                                <span
                                    v-if="isMissingPdpDescription(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    desc
                                </span>
                                <span
                                    v-if="isMissingSellingPrice(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    selling
                                </span>
                                <span
                                    v-if="isMissingBarcode(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    barcode
                                </span>

                                <span
                                    v-if="
                                        !isMissingPdpImages(p) &&
                                        !isMissingPdpDescription(p) &&
                                        !isMissingSellingPrice(p) &&
                                        !isMissingBarcode(p)
                                    "
                                    class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-900"
                                >
                                    ok
                                </span>
                            </button>

                            <div v-else class="flex flex-wrap gap-1">
                                <span
                                    v-if="isMissingPdpImages(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    images
                                </span>
                                <span
                                    v-if="isMissingPdpDescription(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    desc
                                </span>
                                <span
                                    v-if="isMissingSellingPrice(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    selling
                                </span>
                                <span
                                    v-if="isMissingBarcode(p)"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
                                >
                                    barcode
                                </span>

                                <span
                                    v-if="
                                        !isMissingPdpImages(p) &&
                                        !isMissingPdpDescription(p) &&
                                        !isMissingSellingPrice(p) &&
                                        !isMissingBarcode(p)
                                    "
                                    class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-900"
                                >
                                    ok
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            <label
                                class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700"
                            >
                                <input
                                    class="h-4 w-4 rounded border-slate-300"
                                    type="checkbox"
                                    :checked="p.is_ready ?? false"
                                    :disabled="editingId === p.id || isTogglingReady(p.id)"
                                    @change="
                                        toggleReady(
                                            p.id,
                                            ($event.target as HTMLInputElement).checked,
                                        )
                                    "
                                    data-testid="product-ready-toggle"
                                />
                                <span class="select-none">{{
                                    (p.is_ready ?? false) ? 'ready' : 'not ready'
                                }}</span>
                            </label>
                        </td>

                        <td class="px-4 py-3">
                            <label
                                class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700"
                            >
                                <input
                                    class="h-4 w-4 rounded border-slate-300"
                                    type="checkbox"
                                    :checked="p.latest_arrival ?? false"
                                    :disabled="editingId === p.id || isTogglingLatestArrival(p.id)"
                                    @change="
                                        toggleLatestArrival(
                                            p.id,
                                            ($event.target as HTMLInputElement).checked,
                                        )
                                    "
                                    data-testid="product-latest-arrival-toggle"
                                />
                                <span class="select-none">{{
                                    (p.latest_arrival ?? false) ? 'latest' : '—'
                                }}</span>
                            </label>
                        </td>

                        <td class="px-4 py-3">
                            <span
                                class="rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                :class="
                                    p.published_on_shopify
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                        : 'border-slate-200 bg-slate-50 text-slate-700'
                                "
                            >
                                {{ p.published_on_shopify ? 'yes' : 'no' }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end">
                                <button
                                    class="mr-2 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                                    type="button"
                                    @click="props.onOpenPoLines(p.id)"
                                >
                                    PO Lines
                                </button>
                                <button
                                    v-if="editingId !== p.id"
                                    class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 transition hover:bg-slate-50"
                                    type="button"
                                    @click="startEdit(p)"
                                >
                                    Edit
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmDialog
        :open="confirmBulkDeleteOpen"
        title="Delete selected products"
        :message="`Delete ${confirmBulkDeleteCount} selected product(s)?`"
        confirm-text="Delete"
        variant="danger"
        :busy="bulkDeleting"
        @cancel="confirmBulkDeleteOpen = false"
        @confirm="confirmBulkDelete"
    />

    <ConfirmDialog
        :open="confirmBulkArchiveOpen"
        title="Archive selected products"
        :message="`Archive ${confirmBulkArchiveCount} selected product(s)? This will export to Shopify as Status=archived.`"
        confirm-text="Archive"
        variant="primary"
        :busy="bulkArchiving"
        @cancel="confirmBulkArchiveOpen = false"
        @confirm="confirmBulkArchive"
    />

    <BulkUpdateDialog
        :open="bulkUpdateOpen"
        :selected-count="selected.size"
        :busy="bulkUpdating"
        :main-type-options="props.mainTypeOptions ?? []"
        :vendor-options="props.vendorOptions ?? []"
        :type-options="props.typeOptions ?? []"
        :grade-options="props.gradeOptions ?? []"
        :scale-options="props.scaleOptions ?? []"
        :series-options="props.seriesOptions ?? []"
        @cancel="bulkUpdateOpen = false"
        @confirm="confirmBulkUpdate"
    />

    <BulkExportDialog
        :open="bulkExportOpen"
        :selected-count="selected.size"
        :busy="bulkExporting"
        @cancel="bulkExportOpen = false"
        @confirm="confirmBulkExport"
    />

    <BulkRecrawlDialog
        :open="bulkRecrawlOpen"
        :selected-count="selected.size"
        :busy="bulkExporting"
        @cancel="bulkRecrawlOpen = false"
        @confirm="confirmBulkRecrawl"
    />
</template>

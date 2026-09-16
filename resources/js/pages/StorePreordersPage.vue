<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import StorePreorderAddOfferDialog from '../components/storePreorders/StorePreorderAddOfferDialog.vue';
import StorePreordersBulkEditDialog from '../components/storePreorders/StorePreordersBulkEditDialog.vue';
import StorePreordersTable from '../components/storePreorders/StorePreordersTable.vue';
import StorePreordersToolbar from '../components/storePreorders/StorePreordersToolbar.vue';
import { api, extractApiError } from '../lib/api';
import type {
    StorePreorderBulkChanges,
    StorePreorderOrderTotals,
    StorePreorderRow,
} from '../types/storePreorder';

type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    preorder_orders?: StorePreorderOrderTotals;
    closing_dates?: string[];
};

const rows = ref<StorePreorderRow[]>([]);
const meta = ref<Paginated<StorePreorderRow>['meta'] | null>(null);
const orderTotals = ref<StorePreorderOrderTotals | null>(null);
const loading = ref(false);
const errorMessage = ref<string | null>(null);
const infoMessage = ref<string | null>(null);
const search = ref('');
const status = ref<'all' | 'open' | 'closed'>('open');
const closesOn = ref('');
const closingDates = ref<string[]>([]);
const hasUnits = ref(false);
const sortBy = ref<'closing' | 'opened' | 'name'>('closing');
const page = ref(1);
const perPage = 50;
const selectedIds = ref<string[]>([]);
const closeTarget = ref<StorePreorderRow | null>(null);
const closing = ref(false);
const deleteTarget = ref<StorePreorderRow | null>(null);
const deleting = ref(false);
const bulkDeleteOpen = ref(false);
const bulkDeleting = ref(false);
const bulkEditOpen = ref(false);
const bulkUpdating = ref(false);
const capDraftById = ref<Record<string, string>>({});
const savingCapId = ref<string | null>(null);
const pushOpen = ref(false);
const pushing = ref(false);
const addOpen = ref(false);

const selectedCount = computed(() => selectedIds.value.length);
const selectedClosedCount = computed(
    () =>
        rows.value.filter((row) => selectedIds.value.includes(row.id) && row.status === 'closed')
            .length,
);

const closeMessage = computed(() => {
    const row = closeTarget.value;
    if (!row) {
        return '';
    }

    return `Close “${row.product_name ?? row.sku}”? Remaining cap becomes 0. The ERP product stays. This does not delete the product.`;
});

const deleteMessage = computed(() => {
    const row = deleteTarget.value;
    if (!row) {
        return '';
    }

    return `Delete the store preorder for “${row.product_name ?? row.sku}”? The offer is removed so you can open it again from the pick list. If this ERP product has no purchase-order or inventory history, it is deleted too.`;
});

const selectedOpenIds = computed(() =>
    rows.value
        .filter((row) => selectedIds.value.includes(row.id) && row.status === 'open')
        .map((row) => row.id),
);

const pushMessage = computed(() => {
    const n =
        selectedOpenIds.value.length > 0 ? selectedOpenIds.value.length : (meta.value?.total ?? 0);
    const noun = n === 1 ? 'store preorder' : 'store preorders';
    const scope = selectedOpenIds.value.length > 0 ? 'selected' : 'open';

    return `Push ${n} ${scope} ${noun} to Shopify? Checkout charges the deposit. They appear on /collections/pre-orders only — not in the regular catalog.`;
});

const bulkDeleteMessage = computed(() => {
    const n = selectedCount.value;
    const noun = n === 1 ? 'store preorder' : 'store preorders';

    return `Delete ${n} selected ${noun}? Offers are removed so you can open them again from the pick list. ERP products with no PO or inventory history are deleted too.`;
});

async function fetchRows(): Promise<void> {
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get<Paginated<StorePreorderRow>>('/api/v1/store-preorders', {
            params: {
                page: page.value,
                per_page: perPage,
                search: search.value.trim() || undefined,
                status: status.value,
                closes_on: closesOn.value || undefined,
                has_units: hasUnits.value ? 1 : undefined,
                sort_by: sortBy.value,
                sort_dir: sortBy.value === 'opened' ? 'desc' : 'asc',
            },
        });
        rows.value = res.data.data;
        meta.value = res.data.meta;
        orderTotals.value = res.data.preorder_orders ?? { order_count: 0, unit_qty: 0 };
        closingDates.value = res.data.closing_dates ?? [];
        if (closesOn.value !== '' && !closingDates.value.includes(closesOn.value)) {
            closesOn.value = '';
        }
        const drafts: Record<string, string> = {};
        for (const row of rows.value) {
            drafts[row.id] = row.cap_qty === null ? '' : String(row.cap_qty);
        }
        capDraftById.value = drafts;
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        loading.value = false;
    }
}

function togglePage(checked: boolean): void {
    const pageIds = rows.value.map((row) => row.id);
    if (checked) {
        selectedIds.value = [...new Set([...selectedIds.value, ...pageIds])];
        return;
    }
    const drop = new Set(pageIds);
    selectedIds.value = selectedIds.value.filter((id) => !drop.has(id));
}

function toggleRow(id: string, checked: boolean): void {
    if (checked) {
        selectedIds.value = [...new Set([...selectedIds.value, id])];
        return;
    }
    selectedIds.value = selectedIds.value.filter((item) => item !== id);
}

async function confirmClose(): Promise<void> {
    const row = closeTarget.value;
    if (!row) {
        return;
    }
    closing.value = true;
    errorMessage.value = null;
    try {
        await api.post(`/api/v1/store-preorders/${row.id}/close`);
        closeTarget.value = null;
        await fetchRows();
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        closing.value = false;
    }
}

async function confirmDelete(): Promise<void> {
    const row = deleteTarget.value;
    if (!row) {
        return;
    }
    deleting.value = true;
    errorMessage.value = null;
    try {
        await api.delete(`/api/v1/store-preorders/${row.id}`);
        selectedIds.value = selectedIds.value.filter((id) => id !== row.id);
        deleteTarget.value = null;
        await fetchRows();
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        deleting.value = false;
    }
}

async function confirmBulkDelete(): Promise<void> {
    if (selectedIds.value.length === 0) {
        return;
    }
    bulkDeleting.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ deleted: number; products_deleted: number }>(
            '/api/v1/store-preorders/bulk-delete',
            { ids: selectedIds.value },
        );
        infoMessage.value = `Deleted ${res.data.deleted} ${res.data.deleted === 1 ? 'offer' : 'offers'}.`;
        selectedIds.value = [];
        bulkDeleteOpen.value = false;
        await fetchRows();
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        bulkDeleting.value = false;
    }
}

async function confirmBulkEdit(changes: StorePreorderBulkChanges): Promise<void> {
    if (selectedIds.value.length === 0) {
        return;
    }
    bulkUpdating.value = true;
    errorMessage.value = null;
    try {
        const res = await api.post<{ updated: number; skipped: number }>(
            '/api/v1/store-preorders/bulk-update',
            { ids: selectedIds.value, changes },
        );
        const skipped =
            res.data.skipped > 0
                ? ` ${res.data.skipped} closed ${res.data.skipped === 1 ? 'row' : 'rows'} skipped.`
                : '';
        infoMessage.value = `Updated ${res.data.updated} ${res.data.updated === 1 ? 'offer' : 'offers'}.${skipped}`;
        bulkEditOpen.value = false;
        await fetchRows();
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        bulkUpdating.value = false;
    }
}

async function confirmPushShopify(): Promise<void> {
    pushing.value = true;
    errorMessage.value = null;
    try {
        const body = selectedOpenIds.value.length > 0 ? { ids: selectedOpenIds.value } : {};
        const res = await api.post<{
            pushed: number;
            failed: number;
            errors: Array<{ sku: string; message: string }>;
        }>('/api/v1/store-preorders/push-shopify', body);
        const fail =
            res.data.failed > 0
                ? ` ${res.data.failed} failed${res.data.errors[0] ? `: ${res.data.errors[0].sku} ${res.data.errors[0].message}` : '.'}`
                : '';
        infoMessage.value = `Pushed ${res.data.pushed} ${res.data.pushed === 1 ? 'offer' : 'offers'} to the Pre-order page.${fail}`;
        pushOpen.value = false;
    } catch (err) {
        errorMessage.value = extractApiError(err);
    } finally {
        pushing.value = false;
    }
}

function setCapDraft(row: StorePreorderRow, value: string): void {
    capDraftById.value = { ...capDraftById.value, [row.id]: value };
}

function parseCapDraft(row: StorePreorderRow): { ok: boolean; value: number | null } {
    const raw = (capDraftById.value[row.id] ?? '').trim();
    if (raw === '') {
        return { ok: true, value: null };
    }
    const n = Number(raw);
    if (!Number.isInteger(n) || n < 1 || n > 9999) {
        return { ok: false, value: null };
    }

    return { ok: true, value: n };
}

async function saveCap(row: StorePreorderRow): Promise<void> {
    if (row.status !== 'open' || savingCapId.value === row.id) {
        return;
    }
    const parsed = parseCapDraft(row);
    if (!parsed.ok) {
        errorMessage.value = 'Cap must be a whole number from 1 to 9999, or empty for no cap.';
        setCapDraft(row, row.cap_qty === null ? '' : String(row.cap_qty));
        return;
    }
    if (parsed.value === row.cap_qty) {
        return;
    }
    savingCapId.value = row.id;
    errorMessage.value = null;
    try {
        const res = await api.patch<{ data: StorePreorderRow }>(
            `/api/v1/store-preorders/${row.id}/cap`,
            { cap_qty: parsed.value },
        );
        const updated = res.data.data;
        rows.value = rows.value.map((item) =>
            item.id === row.id
                ? {
                      ...updated,
                      order_count: updated.order_count ?? item.order_count,
                      unit_qty: updated.unit_qty ?? item.unit_qty,
                  }
                : item,
        );
        setCapDraft(updated, updated.cap_qty === null ? '' : String(updated.cap_qty));
    } catch (err) {
        errorMessage.value = extractApiError(err);
        setCapDraft(row, row.cap_qty === null ? '' : String(row.cap_qty));
    } finally {
        savingCapId.value = null;
    }
}

watch([status, sortBy, closesOn, hasUnits], async () => {
    page.value = 1;
    await fetchRows();
});

onMounted(async () => {
    await fetchRows();
});
</script>

<template>
    <div class="space-y-4">
        <StorePreordersToolbar
            :search="search"
            :status="status"
            :sort-by="sortBy"
            :closes-on="closesOn"
            :closing-dates="closingDates"
            :has-units="hasUnits"
            :loading="loading"
            :selected-count="selectedCount"
            :bulk-busy="bulkUpdating || bulkDeleting"
            :push-busy="pushing"
            :order-totals="orderTotals"
            @update:search="search = $event"
            @update:status="status = $event"
            @update:sort-by="sortBy = $event"
            @update:closes-on="closesOn = $event"
            @update:has-units="hasUnits = $event"
            @search="
                page = 1;
                fetchRows();
            "
            @clear-selection="selectedIds = []"
            @bulk-edit="bulkEditOpen = true"
            @bulk-delete="bulkDeleteOpen = true"
            @push-shopify="pushOpen = true"
            @add-offer="addOpen = true"
        />

        <p v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</p>
        <p v-if="infoMessage" class="text-sm text-emerald-800">{{ infoMessage }}</p>

        <StorePreordersTable
            :rows="rows"
            :loading="loading"
            :selected-ids="selectedIds"
            :cap-draft-by-id="capDraftById"
            :saving-cap-id="savingCapId"
            @toggle-page="togglePage"
            @toggle-row="toggleRow"
            @update-cap-draft="setCapDraft"
            @save-cap="saveCap"
            @close-row="closeTarget = $event"
            @delete-row="deleteTarget = $event"
        />

        <div v-if="meta" class="flex items-center justify-between text-sm text-slate-600">
            <span
                >Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} rows</span
            >
            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
                    :disabled="page <= 1 || loading"
                    @click="
                        page--;
                        fetchRows();
                    "
                >
                    Prev
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
                    :disabled="!meta || page >= meta.last_page || loading"
                    @click="
                        page++;
                        fetchRows();
                    "
                >
                    Next
                </button>
            </div>
        </div>

        <StorePreorderAddOfferDialog
            :open="addOpen"
            :busy="false"
            @cancel="addOpen = false"
            @saved="
                addOpen = false;
                infoMessage = 'Opened store preorder and queued Shopify publish.';
                fetchRows();
            "
        />
        <StorePreordersBulkEditDialog
            :open="bulkEditOpen"
            :selected-count="selectedCount"
            :closed-count="selectedClosedCount"
            :busy="bulkUpdating"
            @cancel="bulkEditOpen = false"
            @confirm="confirmBulkEdit"
        />
        <ConfirmDialog
            :open="closeTarget !== null"
            title="Close store preorder?"
            :message="closeMessage"
            confirm-text="Close offer"
            variant="danger"
            :busy="closing"
            @confirm="confirmClose"
            @cancel="closeTarget = null"
        />
        <ConfirmDialog
            :open="deleteTarget !== null"
            title="Delete store preorder?"
            :message="deleteMessage"
            confirm-text="Delete offer"
            variant="danger"
            :busy="deleting"
            @confirm="confirmDelete"
            @cancel="deleteTarget = null"
        />
        <ConfirmDialog
            :open="pushOpen"
            title="Push store preorders to Shopify?"
            :message="pushMessage"
            confirm-text="Push to store"
            :busy="pushing"
            @confirm="confirmPushShopify"
            @cancel="pushOpen = false"
        />
        <ConfirmDialog
            :open="bulkDeleteOpen"
            title="Delete selected store preorders?"
            :message="bulkDeleteMessage"
            confirm-text="Delete selected"
            variant="danger"
            :busy="bulkDeleting"
            @confirm="confirmBulkDelete"
            @cancel="bulkDeleteOpen = false"
        />
    </div>
</template>

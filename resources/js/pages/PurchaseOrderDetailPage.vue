<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';
import { formatMoney2, formatMoney2OrEmpty, parseMoney } from '../lib/money';
import BulkUpdatePoItemsDialog, {
    type PoItemsBulkChanges,
} from '../components/purchaseOrders/BulkUpdatePoItemsDialog.vue';
import BulkExportDialog, {
    type ProductsBulkExportType,
} from '../components/products/BulkExportDialog.vue';
import BulkRecrawlDialog, {
    type ProductsRecrawlSource,
} from '../components/products/BulkRecrawlDialog.vue';
import ImportHandlesCard from '../components/products/ImportHandlesCard.vue';
import ImportInventoryQuantityOverrideCard from '../components/products/ImportInventoryQuantityOverrideCard.vue';

type PurchaseOrderItem = {
    id: number;
    product_id: string | null;
    product_name: string | null;
    product_barcode: string | null;
    product_handle: string | null;
    sku: string;
    vendor: string;
    unit_cost: string | null;
    qty_ordered: number | null;
    qty_shipped: number | null;
    qty_received: number | null;
};

type InventoryCheckPickRow = {
    id: string;
    name: string | null;
    source: string | null;
    workflow_state?: string | null;
    created_by_role?: string | null;
    created_at: string | null;
    counts: {
        items: number;
        matched: number;
        unmatched: number;
        ambiguous: number;
        applied: number;
    };
};

type ApplyInventoryCheckWarning = {
    kind: string;
    sku?: string;
    purchase_order_item_id?: number;
    quantity_in_store?: number;
};

type PurchaseOrder = {
    id: string;
    vendor: string;
    vendor_currency_code: string;
    ordered_date: string | null;
    shipped_date: string | null;
    estimated_arrival_date: string | null;
    received_date: string | null;
    fully_on_shelves_date: string | null;
    shipping_total: string | null;
    surcharge_total: string | null;
    product_total: string | null;
    vendor_product_total: string | null;
    fx_rate_to_cad: string | null;
    fx_rate_cad_to_vendor: string | null;
    notes: string | null;
    workflow_checklist: Record<string, boolean> | null;
    counts: { items: number };
    items: PurchaseOrderItem[];
    created_at: string | null;
};

const route = useRoute();
const router = useRouter();
const id = computed(() => String(route.params.id ?? ''));

const loading = ref(false);
const error = ref<string | null>(null);
const po = ref<PurchaseOrder | null>(null);
const saving = ref(false);
const editOpen = ref(false);
const deleting = ref(false);
const reimporting = ref(false);
const importMoreing = ref(false);
const reimportFile = ref<File | null>(null);
const reimportError = ref<string | null>(null);
/** When true, replace re-import deletes PO-linked lots/movements and clears qty received before replacing lines. */
const reimportResetReceipt = ref(false);

const savingQtyOrdered = ref<number | null>(null);
const editingQtyOrderedId = ref<number | null>(null);
const qtyOrderedDrafts = reactive<Record<number, string>>({});

const savingQtyShipped = ref<number | null>(null);
const editingQtyShippedId = ref<number | null>(null);
const qtyShippedDrafts = reactive<Record<number, string>>({});

const savingQtyReceived = ref<number | null>(null);
const editingQtyReceivedId = ref<number | null>(null);
const qtyReceivedDrafts = reactive<Record<number, string>>({});

const itemQtyError = ref<string | null>(null);

const selectedItemIds = ref<Set<number>>(new Set());
const bulkUpdateOpen = ref(false);
const bulkUpdating = ref(false);
const bulkError = ref<string | null>(null);

const poProductUuids = computed<string[]>(() => {
    const items = po.value?.items ?? [];
    const out: string[] = [];
    for (const it of items) {
        const uuid = typeof it.product_id === 'string' ? it.product_id.trim() : '';
        if (uuid) out.push(uuid);
    }
    return Array.from(new Set(out));
});

const poHasProducts = computed<boolean>(() => poProductUuids.value.length > 0);

const exportDialogOpen = ref(false);
const exportBusy = ref(false);
const exportError = ref<string | null>(null);

const recrawlDialogOpen = ref(false);
const recrawlBusy = ref(false);
const recrawlError = ref<string | null>(null);

const importHandlesOpen = ref(false);
const importQtyOpen = ref(false);
const applyingReceivedToAvailable = ref(false);
const applyReceivedError = ref<string | null>(null);
const applyReceivedSummary = ref<string | null>(null);

const inventoryChecksLoading = ref(false);
const inventoryChecksList = ref<InventoryCheckPickRow[]>([]);
const inventoryChecksMetaLastPage = ref(1);
const selectedInventoryCheckId = ref<string>('');
const applyingInventoryCheck = ref(false);
const applyInventoryCheckError = ref<string | null>(null);
const applyInventoryCheckSummary = ref<string | null>(null);
const applyInventoryCheckWarnings = ref<ApplyInventoryCheckWarning[]>([]);

const checklistBusy = ref(false);
const checklistError = ref<string | null>(null);

type WorkflowChecklistKey =
    | 'import_po'
    | 'crawl_desc_image_price'
    | 'set_selling_price'
    | 'ensure_all_products_have_barcode'
    | 'export_to_shopify_get_handles'
    | 'import_handle_only'
    | 'update_product_available_with_shopify_current_inventory_quantity'
    | 'mark_latest_arrival_and_published_on_shopify'
    | 'import_product_available_quantity';

const checklistLabels: Array<{ key: WorkflowChecklistKey; label: string }> = [
    { key: 'import_po', label: 'Import PO' },
    { key: 'crawl_desc_image_price', label: 'Crawl desc, image, price (new products only)' },
    { key: 'set_selling_price', label: 'Set selling price (new/existing products)' },
    { key: 'ensure_all_products_have_barcode', label: 'Ensure all products have barcode' },
    {
        key: 'export_to_shopify_get_handles',
        label: 'Export to Shopify to get handles (new products only)',
    },
    {
        key: 'import_handle_only',
        label: 'Import the HANDLE ONLY back into the system (new products only)',
    },
    {
        key: 'update_product_available_with_shopify_current_inventory_quantity',
        label: 'Add qty received to qty available',
    },
    {
        key: 'mark_latest_arrival_and_published_on_shopify',
        label: 'Mark products as latest arrival and published',
    },
    { key: 'import_product_available_quantity', label: 'Import product available quantity' },
];

const checklist = computed<Record<WorkflowChecklistKey, boolean>>(() => {
    const raw = po.value?.workflow_checklist ?? {};
    return {
        import_po: Boolean((raw as any)?.import_po),
        crawl_desc_image_price: Boolean((raw as any)?.crawl_desc_image_price),
        set_selling_price: Boolean((raw as any)?.set_selling_price),
        ensure_all_products_have_barcode: Boolean((raw as any)?.ensure_all_products_have_barcode),
        export_to_shopify_get_handles: Boolean((raw as any)?.export_to_shopify_get_handles),
        import_handle_only: Boolean((raw as any)?.import_handle_only),
        update_product_available_with_shopify_current_inventory_quantity: Boolean(
            (raw as any)?.update_product_available_with_shopify_current_inventory_quantity,
        ),
        mark_latest_arrival_and_published_on_shopify: Boolean(
            (raw as any)?.mark_latest_arrival_and_published_on_shopify,
        ),
        import_product_available_quantity: Boolean((raw as any)?.import_product_available_quantity),
    };
});

function parseFilenameFromContentDisposition(header: string | undefined): string | null {
    if (!header) return null;
    const m = /filename\*?=(?:UTF-8''|\"?)([^\";]+)\"?/i.exec(header);
    if (!m) return null;
    try {
        return decodeURIComponent(m[1]);
    } catch {
        return m[1];
    }
}

async function bulkExportSelected(
    ids: string[],
    exportType: ProductsBulkExportType,
): Promise<void> {
    if (exportType === 'shopify_content_rename_export') {
        const out = await bulkRenamePlamodAssets(ids);
        if (!out.batchId) {
            throw new Error('Failed to queue rename (missing batch id).');
        }

        // Store the selected ids temporarily so Sync Progress can auto-export once the rename batch finishes.
        // (Avoids putting hundreds of UUIDs in the URL.)
        try {
            sessionStorage.setItem(
                `auto_export_shopify_content:${out.batchId}`,
                JSON.stringify({ ids }),
            );
        } catch {
            // If storage fails (private mode), fall back to requiring a manual export.
        }

        await router.push({
            name: 'sync-progress',
            query: { batch_id: out.batchId, auto_export: 'shopify_content' },
        });
        return;
    }

    if (exportType === 'shopify_content') {
        const res = await api.post<{
            download_url: string;
        }>(
            '/api/v1/products/exports/shopify-content/prepare',
            { ids },
            { validateStatus: () => true },
        );

        if (res.status !== 200) {
            const anyData = res.data as any;
            const msgRaw: unknown = anyData?.message ?? anyData?.error ?? anyData?.errors;
            let details = '';
            if (typeof msgRaw === 'string') details = msgRaw.trim();
            else if (msgRaw !== null && msgRaw !== undefined) {
                try {
                    details = JSON.stringify(msgRaw);
                } catch {
                    details = String(msgRaw);
                }
            }
            throw new Error(`Export failed (HTTP ${res.status}).${details ? ` ${details}` : ''}`);
        }

        const downloadUrl = res.data.download_url;
        if (!downloadUrl) {
            throw new Error('export_failed');
        }

        window.location.assign(downloadUrl);
        return;
    }

    const res = await api.post(
        '/api/v1/products/export/selected',
        {
            export_type: exportType,
            ids,
            sort_by: 'sku',
            sort_dir: 'asc',
            // For PO detail, export must include all products in the PO (even if selling price isn't set yet).
            include_missing_selling_price: exportType === 'shopify',
        },
        {
            responseType: 'blob',
            validateStatus: () => true,
        },
    );

    if (res.status !== 200) {
        let details = '';
        try {
            const blob = res.data as Blob;
            const text = typeof blob?.text === 'function' ? await blob.text() : '';
            details = text.trim();
        } catch {
            // ignore
        }
        throw new Error(`Export failed (HTTP ${res.status}).${details ? ` ${details}` : ''}`);
    }

    const header = (res.headers as Record<string, string | undefined>)['content-disposition'];
    const filename =
        parseFilenameFromContentDisposition(header) ?? `po-products-selected-${exportType}.csv`;

    const blob = res.data as Blob;
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
}

async function bulkRenamePlamodAssets(ids: string[]): Promise<{ queued: number; batchId: string }> {
    const res = await api.post<{ ok: boolean; queued: number; batch_id: string }>(
        '/api/v1/products/bulk/plamod-assets/rename',
        { ids },
        { validateStatus: () => true },
    );

    if (res.status !== 202) {
        const anyData = res.data as any;
        const msgRaw: unknown = anyData?.message ?? anyData?.error ?? anyData?.errors;
        let details = '';
        if (typeof msgRaw === 'string') details = msgRaw.trim();
        else if (msgRaw !== null && msgRaw !== undefined) {
            try {
                details = JSON.stringify(msgRaw);
            } catch {
                details = String(msgRaw);
            }
        }
        throw new Error(
            `Failed to queue rename (HTTP ${res.status}).${details ? ` ${details}` : ''}`,
        );
    }

    return {
        queued: res.data.queued ?? 0,
        batchId: (res.data.batch_id ?? '').trim(),
    };
}

async function bulkRecrawlSelected(ids: string[], sources: ProductsRecrawlSource[]): Promise<void> {
    const res = await api.post<{ ok: boolean; batch_id: string; queued: number }>(
        '/api/v1/products/recrawl/selected',
        { ids, sources },
        { validateStatus: () => true },
    );
    if (res.status !== 202 || !res.data?.batch_id) {
        const status = res.status;
        const anyData = res.data as any;
        const rawMessage: unknown = anyData?.message ?? anyData?.error ?? anyData?.errors;

        let details = '';
        if (typeof rawMessage === 'string') {
            details = rawMessage.trim();
        } else if (rawMessage !== null && rawMessage !== undefined) {
            try {
                details = JSON.stringify(rawMessage);
            } catch {
                details = String(rawMessage);
            }
        }

        throw new Error(`Failed to queue recrawl (HTTP ${status}).${details ? ` ${details}` : ''}`);
    }
    await router.push({ name: 'sync-progress', query: { batch_id: res.data.batch_id } });
}

async function onConfirmExport(payload: { exportType: ProductsBulkExportType }): Promise<void> {
    if (!poHasProducts.value) return;
    exportError.value = null;
    exportBusy.value = true;
    try {
        await bulkExportSelected(poProductUuids.value, payload.exportType);
        exportDialogOpen.value = false;
    } catch (e: unknown) {
        exportError.value = e instanceof Error ? e.message : 'Export failed.';
    } finally {
        exportBusy.value = false;
    }
}

async function onConfirmRecrawl(payload: { sources: ProductsRecrawlSource[] }): Promise<void> {
    if (!poHasProducts.value) return;
    recrawlError.value = null;
    recrawlBusy.value = true;
    try {
        await bulkRecrawlSelected(poProductUuids.value, payload.sources);
        recrawlDialogOpen.value = false;
    } catch (e: unknown) {
        recrawlError.value = e instanceof Error ? e.message : 'Failed to queue recrawl.';
    } finally {
        recrawlBusy.value = false;
    }
}

async function toggleChecklist(key: WorkflowChecklistKey, next: boolean): Promise<void> {
    if (!po.value) return;
    checklistError.value = null;
    checklistBusy.value = true;

    // Optimistic update
    const prev = po.value.workflow_checklist ?? {};
    po.value.workflow_checklist = { ...prev, [key]: next };

    try {
        const res = await api.patch<{ data: PurchaseOrder }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-checklist`,
            { [key]: next },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as any)?.message as string | undefined;
            throw new Error(msg ?? `Failed to save checklist (HTTP ${res.status}).`);
        }
        const updatedPo = (res.data as any)?.data as PurchaseOrder | undefined;
        if (updatedPo && po.value) {
            po.value.workflow_checklist =
                updatedPo.workflow_checklist ?? po.value.workflow_checklist;
        }
    } catch (e: unknown) {
        // revert
        po.value.workflow_checklist = prev;
        checklistError.value = e instanceof Error ? e.message : 'Failed to save checklist.';
    } finally {
        checklistBusy.value = false;
    }
}

function inventoryCheckLabel(c: InventoryCheckPickRow): string {
    const date = formatTorontoDateTime(c.created_at) || '—';
    const st = c.workflow_state ?? '—';
    const src = c.source ?? '—';
    const role = c.created_by_role ?? '—';
    const n = c.counts?.items ?? 0;

    return `${date} · ${st} · ${src} · ${role} · ${n} rows · ${c.id.slice(0, 8)}…`;
}

function formatApplyInventoryCheckWarning(w: ApplyInventoryCheckWarning): string {
    switch (w.kind) {
        case 'po_line_no_inventory_match':
            return `PO line #${w.purchase_order_item_id ?? '?'}: no inventory check row for SKU "${w.sku ?? '?'}"`;
        case 'check_sku_not_on_po':
            return `Check has SKU "${w.sku ?? '?'}" (${w.quantity_in_store ?? 0} scanned) but no matching PO line`;
        case 'po_line_empty_sku':
            return `PO line #${w.purchase_order_item_id ?? '?'}: empty SKU — skipped`;
        default:
            return `${w.kind}${w.sku != null ? ` (${w.sku})` : ''}`;
    }
}

async function loadInventoryChecksForPicker(): Promise<void> {
    inventoryChecksLoading.value = true;
    try {
        const res = await api.get<{
            data: InventoryCheckPickRow[];
            meta: { last_page: number };
        }>('/api/v1/inventory-check', { params: { per_page: 200 } });
        inventoryChecksList.value = res.data.data;
        inventoryChecksMetaLastPage.value = res.data.meta?.last_page ?? 1;
    } catch {
        inventoryChecksList.value = [];
        inventoryChecksMetaLastPage.value = 1;
    } finally {
        inventoryChecksLoading.value = false;
    }
}

async function applyInventoryCheckToQtyReceived(): Promise<void> {
    if (!po.value || selectedInventoryCheckId.value.trim() === '') return;
    applyingInventoryCheck.value = true;
    applyInventoryCheckError.value = null;
    applyInventoryCheckSummary.value = null;
    applyInventoryCheckWarnings.value = [];
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                lines_updated: number;
                warnings: ApplyInventoryCheckWarning[];
                reset?: {
                    movements_deleted: number;
                    lots_deleted: number;
                    qty_received_cleared: number;
                };
            };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/apply-inventory-check`,
            { inventory_check_id: selectedInventoryCheckId.value.trim() },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Failed to apply inventory check (HTTP ${res.status}).`);
        }
        const data = (
            res.data as {
                data?: {
                    lines_updated: number;
                    warnings: ApplyInventoryCheckWarning[];
                    reset?: {
                        movements_deleted: number;
                        lots_deleted: number;
                        qty_received_cleared: number;
                    };
                };
            }
        ).data ?? { lines_updated: 0, warnings: [] };
        const r = data.reset;
        const resetPart =
            r != null
                ? ` Cleared ${r.qty_received_cleared} line(s); removed ${r.lots_deleted} lot(s) and ${r.movements_deleted} movement(s).`
                : '';
        applyInventoryCheckSummary.value = `Updated qty received on ${data.lines_updated} line(s) from the selected inventory check.${resetPart}`;
        applyInventoryCheckWarnings.value = Array.isArray(data.warnings) ? data.warnings : [];
        for (const k of Object.keys(qtyReceivedDrafts)) {
            delete qtyReceivedDrafts[Number(k)];
        }
        await load();
    } catch (e: unknown) {
        applyInventoryCheckError.value =
            e instanceof Error ? e.message : 'Failed to apply inventory check.';
    } finally {
        applyingInventoryCheck.value = false;
    }
}

async function applyReceivedQtyToAvailable(): Promise<void> {
    if (!po.value) return;
    applyingReceivedToAvailable.value = true;
    applyReceivedError.value = null;
    applyReceivedSummary.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                products_updated: number;
                total_added: number;
                lines_considered: number;
                skipped_missing_product_id: number;
                skipped_non_positive_qty: number;
            };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/apply-received-to-available`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as any)?.message as string | undefined;
            throw new Error(msg ?? `Failed to apply received qty (HTTP ${res.status}).`);
        }
        const data = (res.data as any)?.data ?? {};
        const productsUpdated = Number(data.products_updated ?? 0);
        const totalAdded = Number(data.total_added ?? 0);
        const skippedNonPositive = Number(data.skipped_non_positive_qty ?? 0);
        const skippedMissingProductId = Number(data.skipped_missing_product_id ?? 0);
        applyReceivedSummary.value =
            `Added ${totalAdded} to available qty across ${productsUpdated} product(s). ` +
            `Skipped ${skippedNonPositive} line(s) with qty_received <= 0 and ${skippedMissingProductId} line(s) missing linked product.`;
    } catch (e: unknown) {
        applyReceivedError.value = e instanceof Error ? e.message : 'Failed to apply received qty.';
    } finally {
        applyingReceivedToAvailable.value = false;
    }
}
const draft = reactive<{
    vendor: string;
    vendor_currency_code: string;
    ordered_date: string;
    shipped_date: string;
    estimated_arrival_date: string;
    received_date: string;
    fully_on_shelves_date: string;
    shipping_total: string;
    surcharge_total: string;
    product_total: string;
    vendor_product_total: string;
    notes: string;
}>({
    vendor: '',
    vendor_currency_code: 'CAD',
    ordered_date: '',
    shipped_date: '',
    estimated_arrival_date: '',
    received_date: '',
    fully_on_shelves_date: '',
    shipping_total: '',
    surcharge_total: '',
    product_total: '',
    vendor_product_total: '',
    notes: '',
});

const totalUnitsReceived = computed<number>(() => {
    return po.value?.items.reduce((sum, it) => sum + (it.qty_received ?? 0), 0) ?? 0;
});

const totalQtyOrdered = computed<number>(() => {
    return po.value?.items.reduce((sum, it) => sum + (it.qty_ordered ?? 0), 0) ?? 0;
});

const totalQtyShipped = computed<number>(() => {
    return po.value?.items.reduce((sum, it) => sum + (it.qty_shipped ?? 0), 0) ?? 0;
});

const totalQtyReceived = computed<number>(() => {
    return po.value?.items.reduce((sum, it) => sum + (it.qty_received ?? 0), 0) ?? 0;
});

const totalSkuCount = computed<number>(() => {
    const items = po.value?.items ?? [];
    const unique = new Set<string>();
    for (const it of items) {
        const sku = it.sku.trim();
        if (sku !== '') unique.add(sku);
    }
    return unique.size;
});

const totalUnitsForAllocation = computed<number>(() => {
    // Default to ordered quantities so ship/unit + landed are stable while the PO is in-flight.
    // Only switch to received-based allocation once the PO is marked received.
    if (po.value?.received_date && totalUnitsReceived.value > 0) return totalUnitsReceived.value;
    return po.value?.items.reduce((sum, it) => sum + (it.qty_ordered ?? 0), 0) ?? 0;
});

const allocationQtyNote = computed<string | null>(() => {
    if (!po.value) return null;
    const hasTotals =
        (po.value.shipping_total ?? '') !== '' || (po.value.surcharge_total ?? '') !== '';
    if (!hasTotals) return null;
    if (totalUnitsForAllocation.value > 0) return null;
    if (po.value.items.length === 0) return null;
    return 'Cannot compute per-unit amounts because all quantities are 0. Set qty ordered/received to enable ship/unit + landed.';
});

function moneyToCents(value: string | null | undefined): number | null {
    if (value === null || value === undefined) return null;
    const s = String(value).trim();
    if (s === '') return null;

    const clean = s.replace(/[^0-9.\-]/g, '');
    if (clean === '' || clean === '-' || !/^-?\d+(\.\d+)?$/.test(clean)) return null;

    const neg = clean.startsWith('-');
    const raw = neg ? clean.slice(1) : clean;
    const parts = raw.split('.', 2);
    const whole = parts[0] === '' ? '0' : parts[0];
    const frac = (parts[1] ?? '').padEnd(3, '0'); // need 3rd digit for rounding
    const cents2 = frac.slice(0, 2);
    const third = Number(frac[2] ?? '0');

    let cents = Number(whole) * 100 + Number(cents2);
    if (third >= 5) cents += 1;

    return neg ? -cents : cents;
}

function centsToMoney(cents: number): string {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    const dollars = Math.floor(abs / 100);
    const rem = abs % 100;
    return `${sign}${dollars}.${String(rem).padStart(2, '0')}`;
}

function perUnitCents(totalCents: number | null, units: number): number | null {
    if (totalCents === null) return null;
    if (units <= 0) return null;
    // half-up rounding for positive values (these totals are non-negative in our domain)
    return Math.floor((totalCents + units / 2) / units);
}

const shippingPerUnitCents = computed<number | null>(() => {
    const total = moneyToCents(po.value?.shipping_total ?? null);
    return perUnitCents(total, totalUnitsForAllocation.value);
});

const surchargePerUnitCents = computed<number | null>(() => {
    const total = moneyToCents(po.value?.surcharge_total ?? null);
    return perUnitCents(total, totalUnitsForAllocation.value);
});

type TotalsCheck = {
    ok: boolean;
    // deltas are header - computed, in cents
    deltas: {
        product: number | null;
        shipping: number | null;
        surcharge: number | null;
        landed_total: number | null;
    };
    computed: {
        product_total: number | null; // cents
        shipping_total_allocated: number | null; // cents (from rounded ship/unit allocation)
        surcharge_total_allocated: number | null; // cents (from rounded surcharge/unit allocation)
        landed_lines_total: number | null; // cents
        po_grand_total: number | null; // cents (header product+shipping+surcharge)
    };
    missing_unit_cost_lines: number;
};

const totalsCheck = computed<TotalsCheck>(() => {
    const toleranceCents = 5;

    const units = totalUnitsForAllocation.value;
    const headerProduct = moneyToCents(po.value?.product_total ?? null);
    const headerShipping = moneyToCents(po.value?.shipping_total ?? null);
    const headerSurcharge = moneyToCents(po.value?.surcharge_total ?? null);

    let computedProduct: number | null = 0;
    if (po.value?.items) {
        for (const it of po.value.items) {
            const qty = (it.qty_received ?? 0) > 0 ? (it.qty_received ?? 0) : (it.qty_ordered ?? 0);
            if (qty <= 0) continue;
            const unitCents = moneyToCents(it.unit_cost ?? null);
            if (unitCents === null) continue;
            computedProduct += unitCents * qty;
        }
    }
    if (!po.value?.items || po.value.items.length === 0) {
        computedProduct = 0;
    }

    const deltaProduct = headerProduct === null ? null : headerProduct - computedProduct;

    // For shipping/surcharge, we crosscheck against "allocated" totals using rounded per-unit cents.
    // This catches cases where the per-unit display rounding cannot reconcile back to the header total.
    let shippingAllocated: number | null = null;
    let surchargeAllocated: number | null = null;
    let deltaShipping: number | null = null;
    let deltaSurcharge: number | null = null;

    if (units > 0 && headerShipping !== null) {
        const perUnitCents = Math.round(headerShipping / units);
        shippingAllocated = perUnitCents * units;
        deltaShipping = headerShipping - shippingAllocated;
    }
    if (units > 0 && headerSurcharge !== null) {
        const perUnitCents = Math.round(headerSurcharge / units);
        surchargeAllocated = perUnitCents * units;
        deltaSurcharge = headerSurcharge - surchargeAllocated;
    }

    const okProduct = deltaProduct === null ? true : Math.abs(deltaProduct) <= toleranceCents;
    const okShipping = deltaShipping === null ? true : Math.abs(deltaShipping) <= toleranceCents;
    const okSurcharge = deltaSurcharge === null ? true : Math.abs(deltaSurcharge) <= toleranceCents;

    // Check-and-balance requested by user:
    // sum(line landed * qty) should reconcile to PO grand total.
    let landedLinesTotal = 0;
    let missingUnitCostLines = 0;
    const shipUnit = shippingPerUnitCents.value ?? 0;
    const surchargeUnit = surchargePerUnitCents.value ?? 0;
    if (po.value?.items) {
        for (const it of po.value.items) {
            const qty = (it.qty_received ?? 0) > 0 ? (it.qty_received ?? 0) : (it.qty_ordered ?? 0);
            if (qty <= 0) continue;
            const unitCents = moneyToCents(it.unit_cost ?? null);
            if (unitCents === null) {
                missingUnitCostLines++;
                continue;
            }
            landedLinesTotal += (unitCents + shipUnit + surchargeUnit) * qty;
        }
    }

    const poGrandTotal = (headerProduct ?? 0) + (headerShipping ?? 0) + (headerSurcharge ?? 0);
    const deltaLandedTotal = poGrandTotal - landedLinesTotal;
    const okLanded = missingUnitCostLines === 0 && Math.abs(deltaLandedTotal) <= toleranceCents;

    return {
        ok: okProduct && okShipping && okSurcharge && okLanded,
        deltas: {
            product: deltaProduct,
            shipping: deltaShipping,
            surcharge: deltaSurcharge,
            landed_total: deltaLandedTotal,
        },
        computed: {
            product_total: computedProduct,
            shipping_total_allocated: shippingAllocated,
            surcharge_total_allocated: surchargeAllocated,
            landed_lines_total: landedLinesTotal,
            po_grand_total: poGrandTotal,
        },
        missing_unit_cost_lines: missingUnitCostLines,
    };
});

function formatCentsDelta(cents: number | null): string {
    if (cents === null) return '—';
    const sign = cents === 0 ? '' : cents > 0 ? '+' : '−';
    const abs = Math.abs(cents);
    return `${sign}$${(abs / 100).toFixed(2)}`;
}

function landedFor(
    unitCost: string | null,
    shipPerUnitCents: number | null,
    surchargeUnitCents: number | null,
): string {
    const unitCents = moneyToCents(unitCost ?? null);
    if (unitCents === null) return '';
    const ship = shipPerUnitCents ?? 0;
    const surcharge = surchargeUnitCents ?? 0;
    return centsToMoney(unitCents + ship + surcharge);
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<{ data: PurchaseOrder }>(`/api/v1/purchase-orders/${id.value}`);
        po.value = res.data.data;
        if (!editOpen.value && po.value) {
            draft.vendor = po.value.vendor ?? '';
            draft.vendor_currency_code = po.value.vendor_currency_code ?? 'CAD';
            draft.ordered_date = po.value.ordered_date ?? '';
            draft.shipped_date = po.value.shipped_date ?? '';
            draft.estimated_arrival_date = po.value.estimated_arrival_date ?? '';
            draft.received_date = po.value.received_date ?? '';
            draft.fully_on_shelves_date = po.value.fully_on_shelves_date ?? '';
            draft.shipping_total = po.value.shipping_total ?? '';
            draft.surcharge_total = po.value.surcharge_total ?? '';
            draft.product_total = po.value.product_total ?? '';
            draft.vendor_product_total = po.value.vendor_product_total ?? '';
            draft.notes = po.value.notes ?? '';
        }
    } catch {
        error.value = 'Failed to load purchase order.';
    } finally {
        loading.value = false;
    }
}

function startEdit(): void {
    if (!po.value) return;
    editOpen.value = true;
    draft.vendor = po.value.vendor ?? '';
    draft.vendor_currency_code = po.value.vendor_currency_code ?? 'CAD';
    draft.ordered_date = po.value.ordered_date ?? '';
    draft.shipped_date = po.value.shipped_date ?? '';
    draft.estimated_arrival_date = po.value.estimated_arrival_date ?? '';
    draft.received_date = po.value.received_date ?? '';
    draft.fully_on_shelves_date = po.value.fully_on_shelves_date ?? '';
    draft.shipping_total = po.value.shipping_total ?? '';
    draft.surcharge_total = po.value.surcharge_total ?? '';
    draft.product_total = po.value.product_total ?? '';
    draft.vendor_product_total = po.value.vendor_product_total ?? '';
    draft.notes = po.value.notes ?? '';
}

function cancelEdit(): void {
    editOpen.value = false;
    error.value = null;
}

async function save(): Promise<void> {
    if (!po.value) return;
    saving.value = true;
    error.value = null;
    try {
        const payload = {
            vendor: draft.vendor.trim(),
            vendor_currency_code: draft.vendor_currency_code.trim().toUpperCase(),
            ordered_date: draft.ordered_date || null,
            shipped_date: draft.shipped_date || null,
            estimated_arrival_date: draft.estimated_arrival_date || null,
            received_date: draft.received_date || null,
            fully_on_shelves_date: draft.fully_on_shelves_date || null,
            shipping_total: draft.shipping_total.trim() === '' ? null : draft.shipping_total.trim(),
            surcharge_total:
                draft.surcharge_total.trim() === '' ? null : draft.surcharge_total.trim(),
            product_total: draft.product_total.trim() === '' ? null : draft.product_total.trim(),
            vendor_product_total:
                draft.vendor_product_total.trim() === '' ? null : draft.vendor_product_total.trim(),
            notes: draft.notes.trim() === '' ? null : draft.notes.trim(),
        };

        const res = await api.patch<{ data: PurchaseOrder }>(
            `/api/v1/purchase-orders/${po.value.id}`,
            payload,
        );
        po.value = res.data.data;
        editOpen.value = false;
    } catch {
        error.value = 'Failed to save purchase order.';
    } finally {
        saving.value = false;
    }
}

async function deletePo(): Promise<void> {
    if (!po.value) return;
    const lineCount = po.value.items?.length ?? po.value.counts.items ?? 0;
    const hasReceived = (po.value.items ?? []).some((it) => (it.qty_received ?? 0) > 0);
    if (hasReceived) {
        error.value =
            'Cannot delete a purchase order that has received inventory. This would corrupt inventory history.';
        return;
    }

    const msg =
        lineCount > 0
            ? `Delete this purchase order and its ${lineCount} line item(s)? This cannot be undone.`
            : 'Delete this purchase order? This cannot be undone.';
    if (!window.confirm(msg)) {
        return;
    }

    deleting.value = true;
    error.value = null;

    try {
        const res = await api.delete<{ message?: string }>(
            `/api/v1/purchase-orders/${po.value.id}`,
            {
                validateStatus: () => true,
            },
        );
        if (res.status !== 200) {
            error.value = res.data?.message ?? 'Failed to delete purchase order.';
            return;
        }
        window.location.assign('/purchase-orders');
    } catch {
        error.value = 'Failed to delete purchase order.';
    } finally {
        deleting.value = false;
    }
}

function startQtyOrderedEdit(itemId: number, current: number | null): void {
    editingQtyOrderedId.value = itemId;
    if (qtyOrderedDrafts[itemId] === undefined) {
        qtyOrderedDrafts[itemId] = current === null ? '' : String(current);
    }
}

function updateQtyOrderedDraft(itemId: number, value: string): void {
    qtyOrderedDrafts[itemId] = value;
}

function commitQtyOrderedEdit(itemId: number): void {
    if (editingQtyOrderedId.value !== itemId) return;
    editingQtyOrderedId.value = null;
    const value = qtyOrderedDrafts[itemId] ?? '';
    delete qtyOrderedDrafts[itemId];
    void saveQtyOrdered(itemId, value);
}

function startQtyShippedEdit(itemId: number, current: number | null): void {
    editingQtyShippedId.value = itemId;
    if (qtyShippedDrafts[itemId] === undefined) {
        qtyShippedDrafts[itemId] = current === null ? '' : String(current);
    }
}

function updateQtyShippedDraft(itemId: number, value: string): void {
    qtyShippedDrafts[itemId] = value;
}

function commitQtyShippedEdit(itemId: number): void {
    if (editingQtyShippedId.value !== itemId) return;
    editingQtyShippedId.value = null;
    const value = qtyShippedDrafts[itemId] ?? '';
    delete qtyShippedDrafts[itemId];
    void saveQtyShipped(itemId, value);
}

function parseQtyOrNull(value: string): number | null {
    const v = value.trim();
    if (v === '') return null;
    const n = Number(v);
    if (!Number.isFinite(n) || !Number.isInteger(n) || n < 0) return null;
    return n;
}

async function saveQtyOrdered(itemId: number, value: string): Promise<void> {
    if (!po.value) return;
    itemQtyError.value = null;
    savingQtyOrdered.value = itemId;

    const next = parseQtyOrNull(value);
    const row = po.value.items.find((it) => it.id === itemId);
    const previous = row?.qty_ordered ?? null;

    try {
        if (row) row.qty_ordered = next;

        const res = await api.patch(
            `/api/v1/purchase-order-items/${itemId}`,
            { qty_ordered: next },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            if (row) row.qty_ordered = previous;
            itemQtyError.value = (res.data as any)?.message ?? 'Failed to save qty ordered.';
            return;
        }

        const saved = (res.data as any)?.data as PurchaseOrderItem | undefined;
        if (saved && row) {
            row.qty_ordered = saved.qty_ordered ?? null;
        }
    } catch {
        if (row) row.qty_ordered = previous;
        itemQtyError.value = 'Failed to save qty ordered.';
    } finally {
        savingQtyOrdered.value = null;
    }
}

async function saveQtyShipped(itemId: number, value: string): Promise<void> {
    if (!po.value) return;
    itemQtyError.value = null;
    savingQtyShipped.value = itemId;

    const next = parseQtyOrNull(value);
    const row = po.value.items.find((it) => it.id === itemId);
    const previous = row?.qty_shipped ?? null;

    try {
        if (row) row.qty_shipped = next;

        const res = await api.patch(
            `/api/v1/purchase-order-items/${itemId}`,
            { qty_shipped: next },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            if (row) row.qty_shipped = previous;
            itemQtyError.value = (res.data as any)?.message ?? 'Failed to save qty shipped.';
            return;
        }

        const saved = (res.data as any)?.data as PurchaseOrderItem | undefined;
        if (saved && row) {
            row.qty_shipped = saved.qty_shipped ?? null;
        }
    } catch {
        if (row) row.qty_shipped = previous;
        itemQtyError.value = 'Failed to save qty shipped.';
    } finally {
        savingQtyShipped.value = null;
    }
}

function toggleAllItems(checked: boolean): void {
    if (!po.value) return;
    if (!checked) {
        selectedItemIds.value = new Set();
        return;
    }
    selectedItemIds.value = new Set(po.value.items.map((x) => x.id));
}

function toggleItemSelection(itemId: number, checked: boolean): void {
    const next = new Set(selectedItemIds.value);
    if (checked) next.add(itemId);
    else next.delete(itemId);
    selectedItemIds.value = next;
}

const allItemsSelected = computed<boolean>(() => {
    if (!po.value || po.value.items.length === 0) return false;
    return selectedItemIds.value.size === po.value.items.length;
});

async function confirmBulkUpdate(payload: { changes: PoItemsBulkChanges }): Promise<void> {
    if (!po.value) return;
    bulkError.value = null;
    bulkUpdating.value = true;

    try {
        const ids = Array.from(selectedItemIds.value);
        if (ids.length === 0) {
            bulkError.value = 'No items selected.';
            return;
        }

        const res = await api.patch(
            `/api/v1/purchase-orders/${po.value.id}/items`,
            { ids, changes: payload.changes },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            bulkError.value = (res.data as any)?.message ?? 'Bulk update failed.';
            return;
        }

        po.value = (res.data as any)?.data ?? po.value;
        bulkUpdateOpen.value = false;
    } catch {
        bulkError.value = 'Bulk update failed.';
    } finally {
        bulkUpdating.value = false;
    }
}

function startQtyReceivedEdit(itemId: number, current: number | null): void {
    editingQtyReceivedId.value = itemId;
    if (qtyReceivedDrafts[itemId] === undefined) {
        qtyReceivedDrafts[itemId] = current === null ? '' : String(current);
    }
}

function updateQtyReceivedDraft(itemId: number, value: string): void {
    qtyReceivedDrafts[itemId] = value;
}

function commitQtyReceivedEdit(itemId: number): void {
    if (editingQtyReceivedId.value !== itemId) return;
    editingQtyReceivedId.value = null;
    const value = qtyReceivedDrafts[itemId] ?? '';
    delete qtyReceivedDrafts[itemId];
    void saveQtyReceived(itemId, value);
}

async function saveQtyReceived(itemId: number, value: string): Promise<void> {
    if (!po.value) return;
    itemQtyError.value = null;
    savingQtyReceived.value = itemId;

    const next = parseQtyOrNull(value);
    const row = po.value.items.find((it) => it.id === itemId);
    const previous = row?.qty_received ?? null;

    try {
        if (row) row.qty_received = next;

        const res = await api.patch(
            `/api/v1/purchase-order-items/${itemId}`,
            { qty_received: next },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            if (row) row.qty_received = previous;
            itemQtyError.value = (res.data as any)?.message ?? 'Failed to save qty received.';
            return;
        }

        const saved = (res.data as any)?.data as PurchaseOrderItem | undefined;
        if (saved && row) {
            row.qty_received = saved.qty_received ?? null;
        }
    } catch {
        if (row) row.qty_received = previous;
        itemQtyError.value = 'Failed to save qty received.';
    } finally {
        savingQtyReceived.value = null;
    }
}

function onReimportFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    reimportFile.value = input.files?.[0] ?? null;
}

async function reimportCsvIntoPo(): Promise<void> {
    await importCsvIntoPo('replace');
}

async function importMoreCsvIntoPo(): Promise<void> {
    await importCsvIntoPo('append');
}

function formatPoImportError(message: string, issues: unknown): string {
    if (!Array.isArray(issues) || issues.length === 0) {
        return message;
    }
    const first = issues[0] as { kind?: string } | undefined;
    if (first?.kind === 'reimport_not_allowed') {
        return message;
    }
    return `${message} (${JSON.stringify(first)})`;
}

async function importCsvIntoPo(mode: 'replace' | 'append'): Promise<void> {
    if (!po.value) return;
    reimportError.value = null;

    if (!reimportFile.value) {
        reimportError.value = 'Please choose a CSV file.';
        return;
    }

    const resetNote =
        mode === 'replace' && reimportResetReceipt.value
            ? '\n\nYou asked to clear PO receipt data first: PO-linked inventory lots and movement rows will be removed and qty received cleared on all lines before lines are replaced. Product available qty is not adjusted.'
            : '';

    const msg =
        mode === 'replace'
            ? po.value.counts.items > 0
                ? `Re-import this CSV into the current PO? This will REPLACE ${po.value.counts.items} existing line item(s) and update product barcodes/names.${resetNote}`
                : `Re-import this CSV into the current PO? This will update product barcodes/names.${resetNote}`
            : `Import additional products into this PO? This will ADD line items and keep the existing ${po.value.counts.items} line item(s).`;

    if (!window.confirm(msg)) return;

    if (mode === 'replace') reimporting.value = true;
    else importMoreing.value = true;

    try {
        const fd = new FormData();
        fd.append('file', reimportFile.value);
        fd.append('vendor', po.value.vendor);
        fd.append('purchase_order_uuid', po.value.id);
        fd.append('import_mode', mode);
        if (mode === 'replace' && reimportResetReceipt.value) {
            fd.append('reset_receipt_before_reimport', '1');
        }

        const res = await api.post('/api/v1/purchase-orders/import', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
            validateStatus: () => true,
        });

        if (res.status !== 200) {
            const message = (res.data as any)?.message as string | undefined;
            const issues = (res.data as any)?.issues as any;
            reimportError.value = formatPoImportError(message ?? 'Re-import failed.', issues);
            return;
        }

        reimportFile.value = null;
        reimportResetReceipt.value = false;
        await load();
    } catch {
        reimportError.value = mode === 'replace' ? 'Re-import failed.' : 'Import more failed.';
    } finally {
        if (mode === 'replace') reimporting.value = false;
        else importMoreing.value = false;
    }
}

watch(id, () => {
    void load();
});

onMounted(() => {
    void load();
    void loadInventoryChecksForPicker();
});
</script>

<template>
    <main class="mx-auto w-full max-w-screen-2xl px-4 py-6">
        <div class="mb-4">
            <h1 class="text-xl font-semibold text-slate-900">Purchase Order Detail</h1>
            <p class="mt-1 text-sm text-slate-600">
                <a class="underline underline-offset-2" href="/purchase-orders">Back to history</a>
            </p>
        </div>

        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
        <p v-else-if="loading" class="text-sm text-slate-600">Loading…</p>

        <div v-else-if="po" class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="text-sm text-slate-800">
                        <div><span class="font-medium">ID:</span> {{ po.id }}</div>
                        <div>
                            <span class="font-medium">Created:</span>
                            {{ formatTorontoDateTime(po.created_at) }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="saving || deleting"
                            @click="startEdit"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="saving || deleting"
                            :title="
                                po.counts.items > 0
                                    ? 'Cannot delete a PO that has items.'
                                    : 'Delete purchase order'
                            "
                            @click="deletePo"
                        >
                            {{ deleting ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="text-xs font-semibold text-slate-800">
                        Re-import CSV into this PO
                    </div>
                    <div
                        class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:items-end"
                    >
                        <div class="lg:col-span-5">
                            <label class="text-xs font-medium text-slate-700">CSV file</label>
                            <input
                                type="file"
                                accept=".csv,text/csv"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                :disabled="reimporting || importMoreing"
                                @change="onReimportFileChange"
                            />
                            <label
                                class="mt-2 flex cursor-pointer items-start gap-2 text-[11px] text-slate-700"
                            >
                                <input
                                    v-model="reimportResetReceipt"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900"
                                    :disabled="reimporting || importMoreing"
                                />
                                <span>
                                    <span class="font-medium text-slate-800"
                                        >Clear PO receipt data first</span
                                    >
                                    (removes PO-linked inventory lots and movement rows, clears
                                    <span class="font-medium">Qty received</span> on all lines, then
                                    replaces lines). Use when re-import was blocked after receiving
                                    stock. Does not change product available qty.
                                </span>
                            </label>
                            <div class="mt-1 text-[11px] text-slate-500">
                                Re-import replaces existing lines unless you use the option above
                                when receipt data exists. Import more appends new lines and keeps
                                the existing lines.
                            </div>
                        </div>
                        <div class="lg:col-span-1 space-y-2">
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="reimporting || importMoreing"
                                @click="reimportCsvIntoPo"
                            >
                                {{ reimporting ? 'Re-importing…' : 'Re-import' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="reimporting || importMoreing"
                                @click="importMoreCsvIntoPo"
                            >
                                {{ importMoreing ? 'Importing…' : 'Import more' }}
                            </button>
                        </div>
                    </div>
                    <p v-if="reimportError" class="mt-2 text-sm text-red-700">
                        {{ reimportError }}
                    </p>
                </div>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <div class="text-xs font-semibold text-slate-800">
                                PO actions (products in this PO)
                            </div>
                            <div class="mt-1 text-xs text-slate-600">
                                Actions below apply to
                                <span class="font-semibold text-slate-900">{{
                                    poProductUuids.length
                                }}</span>
                                product(s) linked to items in this PO.
                            </div>
                            <div v-if="!poHasProducts" class="mt-2 text-xs text-rose-700">
                                No products are linked to this PO yet (product_id missing on PO
                                items), so these actions are disabled.
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!poHasProducts || recrawlBusy"
                                @click="recrawlDialogOpen = true"
                            >
                                Recrawl
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!poHasProducts || exportBusy"
                                @click="exportDialogOpen = true"
                            >
                                Export products to Shopify (get handles)
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!poHasProducts"
                                @click="importHandlesOpen = !importHandlesOpen"
                            >
                                Import product handles
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!poHasProducts"
                                @click="importQtyOpen = !importQtyOpen"
                            >
                                Import product quantity
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-emerald-200 bg-white px-3 py-1.5 text-sm text-emerald-800 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!poHasProducts || applyingReceivedToAvailable"
                                @click="applyReceivedQtyToAvailable"
                            >
                                {{
                                    applyingReceivedToAvailable
                                        ? 'Applying received qty…'
                                        : 'Add qty received to available'
                                }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 border-t border-slate-200 pt-4">
                        <div class="text-xs font-semibold text-slate-800">
                            Qty received from inventory check
                        </div>
                        <p class="mt-1 text-xs text-slate-600">
                            First clears <span class="font-medium">Qty received</span> on every line
                            and removes
                            <span class="font-medium">PO-linked inventory lots</span> (and their
                            movement rows), then sets qty received from the check by
                            <span class="font-medium">SKU</span> (trimmed) using
                            <span class="font-medium">Qty in store</span>. Product on-hand is not
                            adjusted here. Shows warnings for mismatches and skipped lines.
                        </p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                            <div class="min-w-[min(100%,320px)] flex-1">
                                <label class="text-xs font-medium text-slate-700"
                                    >Inventory check</label
                                >
                                <select
                                    v-model="selectedInventoryCheckId"
                                    class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                    :disabled="inventoryChecksLoading || applyingInventoryCheck"
                                >
                                    <option value="">
                                        {{
                                            inventoryChecksLoading
                                                ? 'Loading checks…'
                                                : 'Choose inventory check…'
                                        }}
                                    </option>
                                    <option
                                        v-for="c in inventoryChecksList"
                                        :key="c.id"
                                        :value="c.id"
                                    >
                                        {{ inventoryCheckLabel(c) }}
                                    </option>
                                </select>
                                <p
                                    v-if="inventoryChecksMetaLastPage > 1"
                                    class="mt-1 text-[11px] text-amber-800"
                                >
                                    Showing the newest 200 sessions only. Use Inventory Check
                                    history for full list.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="
                                    selectedInventoryCheckId.trim() === '' ||
                                    applyingInventoryCheck ||
                                    inventoryChecksLoading
                                "
                                @click="applyInventoryCheckToQtyReceived"
                            >
                                {{ applyingInventoryCheck ? 'Applying…' : 'Apply to qty received' }}
                            </button>
                        </div>
                        <p v-if="applyInventoryCheckError" class="mt-2 text-sm text-rose-700">
                            {{ applyInventoryCheckError }}
                        </p>
                        <p v-if="applyInventoryCheckSummary" class="mt-2 text-sm text-emerald-800">
                            {{ applyInventoryCheckSummary }}
                        </p>
                        <div
                            v-if="applyInventoryCheckWarnings.length > 0"
                            class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2"
                        >
                            <div class="text-xs font-semibold text-amber-900">
                                Warnings ({{ applyInventoryCheckWarnings.length }})
                            </div>
                            <ul class="mt-1 list-inside list-disc text-xs text-amber-950">
                                <li v-for="(w, idx) in applyInventoryCheckWarnings" :key="idx">
                                    {{ formatApplyInventoryCheckWarning(w) }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <p v-if="exportError" class="mt-3 text-sm text-rose-700">{{ exportError }}</p>
                    <p v-if="recrawlError" class="mt-3 text-sm text-rose-700">{{ recrawlError }}</p>
                    <p v-if="applyReceivedError" class="mt-3 text-sm text-rose-700">
                        {{ applyReceivedError }}
                    </p>
                    <p v-if="applyReceivedSummary" class="mt-3 text-sm text-emerald-800">
                        {{ applyReceivedSummary }}
                    </p>

                    <div v-if="importHandlesOpen" class="mt-4">
                        <ImportHandlesCard :embedded="true" :purchase-order-uuid="po.id" />
                    </div>
                    <div v-if="importQtyOpen" class="mt-4">
                        <ImportInventoryQuantityOverrideCard
                            :embedded="true"
                            :purchase-order-uuid="po.id"
                        />
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold text-slate-800">
                                Workflow checklist
                            </div>
                            <div class="mt-1 text-xs text-slate-600">
                                This checklist is saved on this PO.
                            </div>
                        </div>
                        <div v-if="checklistBusy" class="text-xs text-slate-600">Saving…</div>
                    </div>

                    <div class="mt-3 space-y-2">
                        <label
                            v-for="row in checklistLabels"
                            :key="row.key"
                            class="flex items-center gap-2 text-sm text-slate-800"
                        >
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="checklistBusy"
                                :checked="checklist[row.key]"
                                @change="
                                    toggleChecklist(
                                        row.key,
                                        ($event.target as HTMLInputElement).checked,
                                    )
                                "
                            />
                            {{ row.label }}
                        </label>
                    </div>

                    <p v-if="checklistError" class="mt-3 text-sm text-rose-700">
                        {{ checklistError }}
                    </p>
                </div>

                <div
                    v-if="editOpen"
                    class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3"
                >
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <div class="lg:col-span-2">
                            <label class="text-xs font-medium text-slate-700">Vendor</label>
                            <input
                                v-model="draft.vendor"
                                type="text"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700">Ordered</label>
                            <input
                                v-model="draft.ordered_date"
                                type="date"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700">Shipped</label>
                            <input
                                v-model="draft.shipped_date"
                                type="date"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700">Received</label>
                            <input
                                v-model="draft.received_date"
                                type="date"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700"
                                >Estimated arrival</label
                            >
                            <input
                                v-model="draft.estimated_arrival_date"
                                type="date"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700">On shelves</label>
                            <input
                                v-model="draft.fully_on_shelves_date"
                                type="date"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                    </div>

                    <div
                        class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:items-end"
                    >
                        <div>
                            <label class="text-xs font-medium text-slate-700">Product total</label>
                            <input
                                v-model="draft.product_total"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700">Shipping total</label>
                            <input
                                v-model="draft.shipping_total"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700"
                                >Surcharge total</label
                            >
                            <input
                                v-model="draft.surcharge_total"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700"
                                >Vendor currency</label
                            >
                            <input
                                v-model="draft.vendor_currency_code"
                                type="text"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="CAD"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700"
                                >Vendor product total</label
                            >
                            <input
                                v-model="draft.vendor_product_total"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                placeholder="0.00"
                            />
                            <div class="mt-1 text-[11px] text-slate-500">
                                FX auto-calculates when currency ≠ CAD.
                            </div>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-2">
                            <label class="text-xs font-medium text-slate-700">Notes</label>
                            <input
                                v-model="draft.notes"
                                type="text"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="saving"
                                @click="save"
                            >
                                {{ saving ? 'Saving…' : 'Save' }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="saving"
                                @click="cancelEdit"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <div class="text-sm text-slate-800">
                    <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-4">
                        <div><span class="font-medium">Vendor:</span> {{ po.vendor }}</div>
                        <div>
                            <span class="font-medium">Currency:</span> {{ po.vendor_currency_code }}
                        </div>
                        <div>
                            <span class="font-medium">Product total:</span>
                            {{ formatMoney2OrEmpty(po.product_total) }}
                        </div>
                        <div>
                            <span class="font-medium">Vendor product total:</span>
                            {{ formatMoney2OrEmpty(po.vendor_product_total) }}
                            <span v-if="po.vendor_product_total" class="text-slate-500">
                                {{ po.vendor_currency_code }}</span
                            >
                        </div>
                        <div>
                            <span class="font-medium">Shipping total:</span>
                            {{ formatMoney2(po.shipping_total) }}
                        </div>
                        <div>
                            <span class="font-medium">Surcharge total:</span>
                            {{ formatMoney2OrEmpty(po.surcharge_total) }}
                        </div>
                        <div>
                            <span class="font-medium">FX CAD→{{ po.vendor_currency_code }}:</span>
                            {{ po.fx_rate_cad_to_vendor ?? '—' }}
                            <span
                                v-if="
                                    po.vendor_currency_code !== 'CAD' && !po.fx_rate_cad_to_vendor
                                "
                                class="font-medium text-rose-700"
                            >
                                (missing)</span
                            >
                        </div>
                        <div>
                            <span class="font-medium">Ship/unit:</span>
                            {{
                                shippingPerUnitCents === null
                                    ? '—'
                                    : centsToMoney(shippingPerUnitCents)
                            }}
                        </div>
                        <div><span class="font-medium">Items:</span> {{ po.counts.items }}</div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span
                            class="inline-flex items-center rounded-full px-2 py-1 font-semibold"
                            :class="
                                totalsCheck.ok
                                    ? 'bg-emerald-50 text-emerald-800'
                                    : 'bg-rose-50 text-rose-800'
                            "
                        >
                            Totals check: {{ totalsCheck.ok ? 'OK' : 'NOT OK' }}
                        </span>
                        <span class="text-slate-600">
                            Product Δ {{ formatCentsDelta(totalsCheck.deltas.product) }} · Shipping
                            Δ {{ formatCentsDelta(totalsCheck.deltas.shipping) }} · Surcharge Δ
                            {{ formatCentsDelta(totalsCheck.deltas.surcharge) }} · Landed Σ Δ
                            {{ formatCentsDelta(totalsCheck.deltas.landed_total) }}
                        </span>
                        <span class="text-slate-500">(±$0.05 allowed)</span>
                    </div>
                    <div
                        v-if="totalsCheck.missing_unit_cost_lines > 0"
                        class="mt-1 text-xs text-rose-700"
                    >
                        {{ totalsCheck.missing_unit_cost_lines }} line(s) are missing unit cost, so
                        landed total cannot fully reconcile.
                    </div>
                    <div v-if="allocationQtyNote" class="mt-2 text-xs text-amber-800">
                        {{ allocationQtyNote }}
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-4">
                        <div>
                            <span class="font-medium">Ordered:</span> {{ po.ordered_date ?? '—' }}
                        </div>
                        <div>
                            <span class="font-medium">Shipped:</span> {{ po.shipped_date ?? '—' }}
                        </div>
                        <div>
                            <span class="font-medium">Estimated arrival:</span>
                            {{ po.estimated_arrival_date ?? '—' }}
                        </div>
                        <div>
                            <span class="font-medium">Received:</span> {{ po.received_date ?? '—' }}
                        </div>
                        <div>
                            <span class="font-medium">On shelves:</span>
                            {{ po.fully_on_shelves_date ?? '—' }}
                        </div>
                    </div>
                    <div v-if="po.notes" class="mt-2">
                        <span class="font-medium">Notes:</span> {{ po.notes }}
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-slate-900">Items</h2>

                <div
                    v-if="selectedItemIds.size > 0"
                    class="mt-3 flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
                >
                    <div class="text-slate-700">
                        <span class="font-semibold">{{ selectedItemIds.size }}</span> selected
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
                            type="button"
                            :disabled="bulkUpdating"
                            @click="selectedItemIds = new Set()"
                        >
                            Clear
                        </button>
                        <button
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
                            type="button"
                            :disabled="bulkUpdating"
                            @click="bulkUpdateOpen = true"
                        >
                            {{ bulkUpdating ? 'Updating…' : 'Update selected' }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="bulkError"
                    class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                >
                    {{ bulkError }}
                </div>

                <p v-if="itemQtyError" class="mt-3 text-sm text-red-700">{{ itemQtyError }}</p>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="text-slate-600">
                            <tr>
                                <th class="px-2 py-1">
                                    <input
                                        class="h-4 w-4 rounded border-slate-300"
                                        type="checkbox"
                                        :checked="allItemsSelected"
                                        :disabled="po.items.length === 0"
                                        @change="
                                            toggleAllItems(
                                                ($event.target as HTMLInputElement).checked,
                                            )
                                        "
                                    />
                                </th>
                                <th class="px-2 py-1">SKU</th>
                                <th class="px-2 py-1">Product</th>
                                <th class="px-2 py-1">Vendor</th>
                                <th class="px-2 py-1 text-right">Unit cost</th>
                                <th class="px-2 py-1 text-right">Ship/unit</th>
                                <th class="px-2 py-1 text-right">Surcharge/unit</th>
                                <th class="px-2 py-1 text-right">Landed</th>
                                <th class="px-2 py-1 text-right">Qty ordered</th>
                                <th class="px-2 py-1 text-right">Qty shipped</th>
                                <th class="px-2 py-1 text-right">Qty received</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-800">
                            <tr
                                v-for="it in po.items"
                                :key="it.id"
                                class="border-t border-slate-200"
                            >
                                <td class="px-2 py-1">
                                    <input
                                        class="h-4 w-4 rounded border-slate-300"
                                        type="checkbox"
                                        :checked="selectedItemIds.has(it.id)"
                                        @change="
                                            toggleItemSelection(
                                                it.id,
                                                ($event.target as HTMLInputElement).checked,
                                            )
                                        "
                                    />
                                </td>
                                <td class="px-2 py-1">{{ it.sku }}</td>
                                <td class="max-w-[28rem] px-2 py-1 text-slate-700">
                                    <div class="min-w-0">
                                        <div class="truncate" :title="it.product_name ?? ''">
                                            {{ it.product_name ?? '' }}
                                        </div>
                                        <div class="mt-0.5 space-y-0.5 text-[11px] text-slate-500">
                                            <div class="truncate">
                                                <span class="font-semibold text-slate-600"
                                                    >Barcode:</span
                                                >
                                                <span class="font-mono">{{
                                                    it.product_barcode ?? '—'
                                                }}</span>
                                            </div>
                                            <div class="truncate">
                                                <span class="font-semibold text-slate-600"
                                                    >Handle:</span
                                                >
                                                <span class="font-mono">{{
                                                    it.product_handle ?? '—'
                                                }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-2 py-1">{{ it.vendor }}</td>
                                <td class="px-2 py-1 text-right">
                                    {{ formatMoney2OrEmpty(it.unit_cost) }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    {{
                                        shippingPerUnitCents === null
                                            ? ''
                                            : centsToMoney(shippingPerUnitCents)
                                    }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    {{
                                        surchargePerUnitCents === null
                                            ? ''
                                            : centsToMoney(surchargePerUnitCents)
                                    }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    {{
                                        landedFor(
                                            it.unit_cost,
                                            shippingPerUnitCents,
                                            surchargePerUnitCents,
                                        )
                                    }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    <input
                                        class="w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-xs tabular-nums text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                        type="text"
                                        inputmode="numeric"
                                        :value="
                                            qtyOrderedDrafts[it.id] ??
                                            (it.qty_ordered === null ? '' : String(it.qty_ordered))
                                        "
                                        :disabled="savingQtyOrdered === it.id"
                                        placeholder=""
                                        @focus="startQtyOrderedEdit(it.id, it.qty_ordered)"
                                        @input="
                                            updateQtyOrderedDraft(
                                                it.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                        @keydown.enter.prevent="commitQtyOrderedEdit(it.id)"
                                        @blur="commitQtyOrderedEdit(it.id)"
                                    />
                                </td>
                                <td class="px-2 py-1 text-right">
                                    <input
                                        class="w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-xs tabular-nums text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                        type="text"
                                        inputmode="numeric"
                                        :value="
                                            qtyShippedDrafts[it.id] ??
                                            (it.qty_shipped === null ? '' : String(it.qty_shipped))
                                        "
                                        :disabled="savingQtyShipped === it.id"
                                        placeholder=""
                                        @focus="startQtyShippedEdit(it.id, it.qty_shipped)"
                                        @input="
                                            updateQtyShippedDraft(
                                                it.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                        @keydown.enter.prevent="commitQtyShippedEdit(it.id)"
                                        @blur="commitQtyShippedEdit(it.id)"
                                    />
                                </td>
                                <td class="px-2 py-1 text-right">
                                    <input
                                        class="w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-xs tabular-nums text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                        type="text"
                                        inputmode="numeric"
                                        :value="
                                            qtyReceivedDrafts[it.id] ??
                                            (it.qty_received === null
                                                ? ''
                                                : String(it.qty_received))
                                        "
                                        :disabled="savingQtyReceived === it.id"
                                        placeholder=""
                                        @focus="startQtyReceivedEdit(it.id, it.qty_received)"
                                        @input="
                                            updateQtyReceivedDraft(
                                                it.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                        @keydown.enter.prevent="commitQtyReceivedEdit(it.id)"
                                        @blur="commitQtyReceivedEdit(it.id)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200 bg-slate-50 text-slate-800">
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2 font-semibold">
                                    Totals
                                    <span class="font-normal text-slate-600">
                                        ({{ totalSkuCount }} SKU{{
                                            totalSkuCount === 1 ? '' : 's'
                                        }})
                                    </span>
                                </td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">
                                    {{ totalQtyOrdered }}
                                </td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">
                                    {{ totalQtyShipped }}
                                </td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">
                                    {{ totalQtyReceived }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>
    </main>

    <BulkUpdatePoItemsDialog
        :open="bulkUpdateOpen"
        :selected-count="selectedItemIds.size"
        :busy="bulkUpdating"
        @cancel="bulkUpdateOpen = false"
        @confirm="confirmBulkUpdate"
    />

    <BulkExportDialog
        :open="exportDialogOpen"
        :selected-count="poProductUuids.length"
        :busy="exportBusy"
        @cancel="exportDialogOpen = false"
        @confirm="onConfirmExport"
    />
    <BulkRecrawlDialog
        :open="recrawlDialogOpen"
        :selected-count="poProductUuids.length"
        :busy="recrawlBusy"
        @cancel="recrawlDialogOpen = false"
        @confirm="onConfirmRecrawl"
    />
</template>

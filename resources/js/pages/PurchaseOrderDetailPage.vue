<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';
import { formatMoney2, formatMoney2OrEmpty, parseMoney } from '../lib/money';
import BulkUpdatePoItemsDialog, {
    type PoItemsBulkChanges,
} from '../components/purchaseOrders/BulkUpdatePoItemsDialog.vue';
import PoWorkflowSetPricesDialog, {
    type PoSetPricePreview,
} from '../components/purchaseOrders/PoWorkflowSetPricesDialog.vue';
import PoWorkflowExportShopifyDialog, {
    type PoExportShopifyPreview,
    type PoExportShopifyPushSummary,
} from '../components/purchaseOrders/PoWorkflowExportShopifyDialog.vue';
import PoWorkflowPullHandlesDialog, {
    type PoPullHandlesPreview,
    type PoPullHandlesSummary,
} from '../components/purchaseOrders/PoWorkflowPullHandlesDialog.vue';
import PoWorkflowPushInventoryDialog, {
    type PoPushInventoryPreview,
    type PoPushInventorySummary,
} from '../components/purchaseOrders/PoWorkflowPushInventoryDialog.vue';
import PoImportPreviewDialog, {
    type PoImportPreview,
} from '../components/purchaseOrders/PoImportPreviewDialog.vue';
import BulkExportDialog, {
    type ProductsBulkExportType,
} from '../components/products/BulkExportDialog.vue';
import BulkRecrawlDialog, {
    type ProductsRecrawlSource,
} from '../components/products/BulkRecrawlDialog.vue';
import ImportHandlesCard from '../components/products/ImportHandlesCard.vue';
import ImportInventoryQuantityOverrideCard from '../components/products/ImportInventoryQuantityOverrideCard.vue';
import ProductPoLinesDrawer from '../components/products/ProductPoLinesDrawer.vue';

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
    available: number | null;
    maintain: number | null;
    not_arrived: number | null;
    reorder: number | null;
    total_ordered: number | null;
    total_sold: number | null;
    latest_landed_unit_cost: string | null;
    selling_price: string | null;
    multiplier: string | null;
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
    supplier_order_id: string | null;
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
    status: 'draft' | 'ordered' | 'shipped' | 'received' | 'on_shelves';
    shipment_method: 'air' | 'sea' | null;
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
const importPreviewOpen = ref(false);
const importPreviewLoading = ref(false);
const importPreviewError = ref<string | null>(null);
const importPreview = ref<PoImportPreview | null>(null);
const pendingImportMode = ref<'replace' | 'append'>('append');
const importProductTotal = ref('');
const importShippingTotal = ref('');
const importProductTotalIncludesFees = ref(false);
/** When true, replace re-import deletes PO-linked lots/movements and clears qty received before replacing lines. */
const reimportResetReceipt = ref(false);

const isPmBrokerVendor = computed(() => {
    const vendor = po.value?.vendor ?? '';
    const normalized = vendor.trim().toLowerCase();
    return normalized === 'dspiae' || normalized === 'stedi';
});

const savingQtyOrdered = ref<number | null>(null);
const editingQtyOrderedId = ref<number | null>(null);
const qtyOrderedDrafts = reactive<Record<number, string>>({});

const savingQtyShipped = ref<number | null>(null);
const editingQtyShippedId = ref<number | null>(null);
const qtyShippedDrafts = reactive<Record<number, string>>({});

const savingQtyReceived = ref<number | null>(null);
const editingQtyReceivedId = ref<number | null>(null);
const qtyReceivedDrafts = reactive<Record<number, string>>({});

const savingBarcodeProductId = ref<string | null>(null);
const editingBarcodeItemId = ref<number | null>(null);
const barcodeDrafts = reactive<Record<number, string>>({});

const savingUnitCost = ref<number | null>(null);
const editingUnitCostId = ref<number | null>(null);
const unitCostDrafts = reactive<Record<number, string>>({});

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
const exportDraftLinesBusy = ref(false);
const exportDraftLinesError = ref<string | null>(null);

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
const workflowVerifyBusy = ref(false);
const workflowActionBusy = ref<Partial<Record<WorkflowChecklistKey, boolean>>>({});
const workflowActionError = ref<string | null>(null);
const inventoryPrepareReady = ref(false);
const inventoryPrepareSummary = ref<string | null>(null);
const clearingStaleLatestArrival = ref(false);
const clearStaleLatestArrivalSummary = ref<string | null>(null);

const setPricesDialogOpen = ref(false);
const setPricesPreview = ref<PoSetPricePreview | null>(null);
const setPricesPreviewLoading = ref(false);
const setPricesApplyBusy = ref(false);
const setPricesPreviewError = ref<string | null>(null);
const exportShopifyDialogOpen = ref(false);
const exportShopifyPreview = ref<PoExportShopifyPreview | null>(null);
const exportShopifyPreviewLoading = ref(false);
const exportShopifyPrepareBusy = ref(false);
const exportShopifyPreviewError = ref<string | null>(null);
const exportShopifyPushSummary = ref<PoExportShopifyPushSummary | null>(null);
const pullHandlesDialogOpen = ref(false);
const pullHandlesPreview = ref<PoPullHandlesPreview | null>(null);
const pullHandlesPreviewLoading = ref(false);
const pullHandlesApplyBusy = ref(false);
const pullHandlesPreviewError = ref<string | null>(null);
const pullHandlesSummary = ref<PoPullHandlesSummary | null>(null);
const pushInventoryDialogOpen = ref(false);
const pushInventoryPreview = ref<PoPushInventoryPreview | null>(null);
const pushInventoryPreviewLoading = ref(false);
const pushInventoryPushBusy = ref(false);
const pushInventoryPreviewError = ref<string | null>(null);
const pushInventoryPushSummary = ref<PoPushInventorySummary | null>(null);
const draftAddSkus = ref('');
const addingDraftProducts = ref(false);
const addDraftProductsError = ref<string | null>(null);
const addDraftProductsSummary = ref<string | null>(null);
const poLinesOpen = ref(false);
const poLinesProductId = ref<string | null>(null);
const poLinesProductSku = ref<string | null>(null);
const poLinesProductName = ref<string | null>(null);

type WorkflowChecklistKey =
    | 'import_po'
    | 'crawl_desc_image_price'
    | 'select_and_arrange_product_images'
    | 'set_selling_price'
    | 'ensure_all_products_have_barcode'
    | 'export_to_shopify_get_handles'
    | 'import_handle_only'
    | 'update_product_available_with_shopify_current_inventory_quantity'
    | 'mark_published_on_shopify'
    | 'mark_latest_arrival'
    | 'import_product_available_quantity';

const checklistLabels: Array<{ key: WorkflowChecklistKey; label: string }> = [
    { key: 'import_po', label: 'Import PO' },
    {
        key: 'crawl_desc_image_price',
        label: 'Crawl desc, image, price (Plamod new products only)',
    },
    {
        key: 'select_and_arrange_product_images',
        label: 'Select and rearrange product images (manual — open each product)',
    },
    { key: 'set_selling_price', label: 'Set/review selling price (new/existing products)' },
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
        key: 'mark_published_on_shopify',
        label: 'Mark products published on Shopify (ERP flag — push sets ACTIVE + channels)',
    },
    {
        key: 'mark_latest_arrival',
        label: 'Mark latest arrival (ERP flag — push adds tag; skips tools; toggle on Products for exceptions)',
    },
    {
        key: 'import_product_available_quantity',
        label: 'Push to Shopify — Latest Arrivals order (content, tags, price, inventory; sorted by type, newest first)',
    },
];

type WorkflowStepStatus = {
    done: boolean;
    checked: boolean;
    newly_checked?: boolean;
    detail?: string;
};

function applyWorkflowPoResponse(data: {
    purchase_order?: PurchaseOrder;
    steps?: Record<string, WorkflowStepStatus>;
}): void {
    if (data.purchase_order && po.value) {
        po.value = { ...po.value, ...data.purchase_order };
    }
}

async function runWorkflowVerify(): Promise<void> {
    if (!po.value) return;
    workflowVerifyBusy.value = true;
    workflowActionError.value = null;
    try {
        const res = await api.post<{ ok: boolean; data: { purchase_order: PurchaseOrder } }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-verify`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as any)?.message as string | undefined;
            throw new Error(msg ?? `Verify failed (HTTP ${res.status}).`);
        }
        applyWorkflowPoResponse(res.data.data ?? {});
    } catch (e: unknown) {
        workflowActionError.value = e instanceof Error ? e.message : 'Workflow verify failed.';
    } finally {
        workflowVerifyBusy.value = false;
    }
}

async function runWorkflowAction(
    key: WorkflowChecklistKey,
    path: string,
    options?: { expect202?: boolean },
): Promise<void> {
    if (!po.value) return;
    workflowActionBusy.value = { ...workflowActionBusy.value, [key]: true };
    workflowActionError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: { purchase_order?: PurchaseOrder; summary?: Record<string, unknown> };
            message?: string;
            issues?: Array<{ sku: string; reason: string }>;
        }>(path, {}, { validateStatus: () => true });
        const expected = options?.expect202 ? 202 : 200;
        if (res.status !== expected) {
            const issues = (res.data as any)?.issues as
                | Array<{ sku: string; reason: string }>
                | undefined;
            let msg = (res.data as any)?.message as string | undefined;
            if (issues?.length) {
                const lines = issues
                    .slice(0, 8)
                    .map((i) => `${i.sku}: ${i.reason}`)
                    .join('; ');
                msg = `${msg ?? 'Action failed.'} ${lines}`;
            }
            throw new Error(msg ?? `Action failed (HTTP ${res.status}).`);
        }
        applyWorkflowPoResponse(res.data.data ?? {});
        const batchId = (res.data.data as { recrawl_batch_id?: string } | undefined)
            ?.recrawl_batch_id;
        if (key === 'crawl_desc_image_price' && batchId) {
            await router.push({
                name: 'sync-progress',
                query: { batch_id: batchId },
            });
        }
    } catch (e: unknown) {
        workflowActionError.value = e instanceof Error ? e.message : 'Workflow action failed.';
    } finally {
        workflowActionBusy.value = { ...workflowActionBusy.value, [key]: false };
    }
}

type PrepareInventoryResponse = {
    ok: boolean;
    data: {
        lines_validated: number;
        sync_mode?:
            | 'skipped_mirror_fresh'
            | 'mirror_stale_confirmation_required'
            | 'po_inventory_refresh';
        mirror_fresh?: boolean;
        max_age_seconds?: number;
        products_last_completed_at?: string | null;
        inventory_levels_last_completed_at?: string | null;
        skus_refreshed?: number;
        inventory_items_refreshed?: number;
        shopify_quantities: Array<{ sku: string }>;
    };
    message?: string;
    issues?: Array<{ sku: string; reason: string }>;
};

function formatShopifyMirrorSyncLabel(iso: string | null | undefined): string {
    if (!iso) return 'never';
    const parsed = new Date(iso);
    if (Number.isNaN(parsed.getTime())) return iso;
    return parsed.toLocaleString();
}

function prepareInventorySummaryFromResponse(data: PrepareInventoryResponse['data']): string {
    const n = data.lines_validated ?? 0;
    const mode = data.sync_mode;
    if (mode === 'skipped_mirror_fresh') {
        return `Catalog mirror is fresh (synced within the last hour). ${n} PO line(s) validated with received qty.`;
    }
    if (mode === 'po_inventory_refresh') {
        const skuCount = data.skus_refreshed ?? n;
        return `Refreshed Shopify inventory for ${skuCount} PO SKU(s). ${n} PO line(s) validated with received qty.`;
    }
    if (mode === 'mirror_stale_confirmation_required') {
        return `Validated ${n} PO line(s) with received qty. Using existing Shopify mirror data (not refreshed).`;
    }
    return `${n} PO line(s) validated with received qty.`;
}

async function postPrepareInventory(
    pullShopify: boolean,
): Promise<PrepareInventoryResponse['data']> {
    if (!po.value) {
        throw new Error('Purchase order is not loaded.');
    }
    const res = await api.post<PrepareInventoryResponse>(
        `/api/v1/purchase-orders/${po.value.id}/workflow-actions/prepare-inventory`,
        pullShopify ? { pull_shopify: true } : {},
        // PO-SKU Shopify inventory pull can exceed the default 60s API timeout.
        { validateStatus: () => true, timeout: 0 },
    );
    if (res.status !== 200) {
        const issues = res.data?.issues ?? [];
        const lines = issues
            .slice(0, 8)
            .map((i) => `${i.sku}: ${i.reason}`)
            .join('; ');
        throw new Error(
            `${res.data?.message ?? 'Prepare inventory failed.'}${lines ? ` ${lines}` : ''}`,
        );
    }
    return res.data.data;
}

async function prepareInventoryForPo(): Promise<void> {
    if (!po.value) return;
    const workflowKey = 'update_product_available_with_shopify_current_inventory_quantity';
    workflowActionBusy.value = {
        ...workflowActionBusy.value,
        [workflowKey]: true,
    };
    workflowActionError.value = null;
    inventoryPrepareReady.value = false;
    inventoryPrepareSummary.value = null;
    try {
        let data = await postPrepareInventory(false);
        if (data.sync_mode === 'mirror_stale_confirmation_required') {
            const maxAgeHours = Math.max(1, Math.round((data.max_age_seconds ?? 3600) / 3600));
            const productsAt = formatShopifyMirrorSyncLabel(data.products_last_completed_at);
            const inventoryAt = formatShopifyMirrorSyncLabel(
                data.inventory_levels_last_completed_at,
            );
            const n = data.lines_validated ?? 0;
            const pullConfirmed = window.confirm(
                `Qty received validated on ${n} PO line(s).\n\nShopify catalog data is older than ${maxAgeHours} hour(s) (products last synced: ${productsAt}; inventory last synced: ${inventoryAt}).\n\nPull fresh inventory for PO SKUs from Shopify now? This may take a few minutes.`,
            );
            if (pullConfirmed) {
                data = await postPrepareInventory(true);
            }
        }
        inventoryPrepareReady.value = true;
        inventoryPrepareSummary.value = prepareInventorySummaryFromResponse(data);
    } catch (e: unknown) {
        const axiosCode = (e as { code?: string })?.code;
        if (axiosCode === 'ECONNABORTED') {
            workflowActionError.value =
                'Prepare timed out in the browser while pulling Shopify inventory. Refresh the page and try Prepare again.';
        } else {
            workflowActionError.value =
                e instanceof Error ? e.message : 'Prepare inventory failed.';
        }
    } finally {
        workflowActionBusy.value = {
            ...workflowActionBusy.value,
            [workflowKey]: false,
        };
    }
}

function workflowRowButtonLabel(key: WorkflowChecklistKey): string | null {
    switch (key) {
        case 'crawl_desc_image_price':
            return 'Crawl new';
        case 'select_and_arrange_product_images':
            return 'Review images';
        case 'set_selling_price':
            return 'Set/review';
        case 'import_handle_only':
            return 'Pull handles';
        case 'update_product_available_with_shopify_current_inventory_quantity':
            return 'Prepare';
        case 'mark_published_on_shopify':
            return 'Mark published';
        case 'mark_latest_arrival':
            return 'Mark latest';
        case 'export_to_shopify_get_handles':
            return 'Export';
        case 'import_product_available_quantity':
            return 'Push to Shopify';
        default:
            return null;
    }
}

function isPlamodVendor(vendor: string | null | undefined): boolean {
    return (vendor ?? '').trim().toLowerCase() === 'plamod';
}

async function onWorkflowRowAction(key: WorkflowChecklistKey): Promise<void> {
    if (!po.value) return;
    switch (key) {
        case 'crawl_desc_image_price':
            if (!isPlamodVendor(po.value.vendor)) {
                const proceed = window.confirm(
                    `Vendor is "${po.value.vendor}". Crawling desc/images/prices is only required for Plamod POs. Run crawl anyway?`,
                );
                if (!proceed) return;
            }
            await patchChecklist({ crawl_desc_image_price_skipped: false });
            await runWorkflowAction(
                key,
                `/api/v1/purchase-orders/${po.value.id}/workflow-actions/crawl-new-products`,
                { expect202: true },
            );
            return;
        case 'select_and_arrange_product_images': {
            const productsRoute = router.resolve({
                name: 'products',
                query: {
                    purchase_order_uuid: po.value.id,
                    po_product_novelty: 'all',
                },
            });
            window.open(productsRoute.href, '_blank', 'noopener,noreferrer');
            return;
        }
        case 'set_selling_price':
            await openSetPricesPreview();
            return;
        case 'import_handle_only':
            await openPullHandlesPreview();
            return;
        case 'update_product_available_with_shopify_current_inventory_quantity':
            await prepareInventoryForPo();
            return;
        case 'mark_published_on_shopify':
            await runWorkflowAction(
                key,
                `/api/v1/purchase-orders/${po.value.id}/workflow-actions/mark-published-on-shopify`,
            );
            return;
        case 'mark_latest_arrival':
            await runWorkflowAction(
                key,
                `/api/v1/purchase-orders/${po.value.id}/workflow-actions/mark-latest-arrival`,
            );
            return;
        case 'export_to_shopify_get_handles':
            await openExportShopifyPreview();
            return;
        case 'import_product_available_quantity':
            await openPushInventoryPreview();
            return;
        default:
            return;
    }
}

async function openSetPricesPreview(): Promise<void> {
    if (!po.value) return;
    setPricesDialogOpen.value = true;
    setPricesPreview.value = null;
    setPricesPreviewError.value = null;
    setPricesPreviewLoading.value = true;
    try {
        const res = await api.get<{ ok: boolean; data: PoSetPricePreview }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/set-prices/preview`,
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Preview failed (HTTP ${res.status}).`);
        }
        setPricesPreview.value = res.data.data;
    } catch (e: unknown) {
        setPricesPreviewError.value =
            e instanceof Error ? e.message : 'Failed to load price preview.';
    } finally {
        setPricesPreviewLoading.value = false;
    }
}

function closeSetPricesDialog(): void {
    if (setPricesApplyBusy.value) return;
    setPricesDialogOpen.value = false;
    setPricesPreview.value = null;
    setPricesPreviewError.value = null;
}

async function openExportShopifyPreview(): Promise<void> {
    if (!po.value) return;
    exportShopifyDialogOpen.value = true;
    exportShopifyPreview.value = null;
    exportShopifyPushSummary.value = null;
    exportShopifyPreviewError.value = null;
    exportShopifyPreviewLoading.value = true;
    try {
        const res = await api.get<{ ok: boolean; data: PoExportShopifyPreview }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/export-shopify-content/preview`,
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Preview failed (HTTP ${res.status}).`);
        }
        exportShopifyPreview.value = res.data.data;
    } catch (e: unknown) {
        exportShopifyPreviewError.value =
            e instanceof Error ? e.message : 'Failed to load export preview.';
    } finally {
        exportShopifyPreviewLoading.value = false;
    }
}

function closeExportShopifyDialog(): void {
    if (exportShopifyPrepareBusy.value) return;
    exportShopifyDialogOpen.value = false;
    exportShopifyPreview.value = null;
    exportShopifyPushSummary.value = null;
    exportShopifyPreviewError.value = null;
}

async function openPushInventoryPreview(): Promise<void> {
    if (!po.value) return;
    pushInventoryDialogOpen.value = true;
    pushInventoryPreview.value = null;
    pushInventoryPushSummary.value = null;
    pushInventoryPreviewError.value = null;
    pushInventoryPreviewLoading.value = true;
    try {
        const res = await api.get<{ ok: boolean; data: PoPushInventoryPreview }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/push-inventory/preview`,
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Preview failed (HTTP ${res.status}).`);
        }
        pushInventoryPreview.value = res.data.data;
    } catch (e: unknown) {
        pushInventoryPreviewError.value =
            e instanceof Error ? e.message : 'Failed to load inventory push preview.';
    } finally {
        pushInventoryPreviewLoading.value = false;
    }
}

function closePushInventoryDialog(): void {
    if (pushInventoryPushBusy.value) return;
    pushInventoryDialogOpen.value = false;
    pushInventoryPreview.value = null;
    pushInventoryPushSummary.value = null;
    pushInventoryPreviewError.value = null;
}

async function confirmPushInventory(): Promise<void> {
    if (!po.value || !pushInventoryPreview.value || pushInventoryPreview.value.push_count === 0) {
        return;
    }
    pushInventoryPushBusy.value = true;
    pushInventoryPreviewError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                summary: PoPushInventorySummary;
                purchase_order?: PurchaseOrder;
            };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/push-inventory`,
            {},
            { validateStatus: () => true, timeout: 0 },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Push failed (HTTP ${res.status}).`);
        }
        pushInventoryPushSummary.value = res.data.data.summary ?? res.data.data;
        if ((pushInventoryPushSummary.value?.failed ?? 0) === 0) {
            applyWorkflowPoResponse(res.data.data ?? {});
            await load();
        }
    } catch (e: unknown) {
        pushInventoryPreviewError.value =
            e instanceof Error ? e.message : 'Failed to push inventory to Shopify.';
    } finally {
        pushInventoryPushBusy.value = false;
    }
}

async function openPullHandlesPreview(): Promise<void> {
    if (!po.value) return;
    pullHandlesDialogOpen.value = true;
    pullHandlesPreview.value = null;
    pullHandlesSummary.value = null;
    pullHandlesPreviewError.value = null;
    pullHandlesPreviewLoading.value = true;
    try {
        const res = await api.get<{ ok: boolean; data: PoPullHandlesPreview }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/pull-handles/preview`,
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Preview failed (HTTP ${res.status}).`);
        }
        pullHandlesPreview.value = res.data.data;
    } catch (e: unknown) {
        pullHandlesPreviewError.value =
            e instanceof Error ? e.message : 'Failed to load pull-handles preview.';
    } finally {
        pullHandlesPreviewLoading.value = false;
    }
}

function closePullHandlesDialog(): void {
    if (pullHandlesApplyBusy.value) return;
    pullHandlesDialogOpen.value = false;
    pullHandlesPreview.value = null;
    pullHandlesSummary.value = null;
    pullHandlesPreviewError.value = null;
}

async function confirmPullHandles(): Promise<void> {
    if (!po.value || !pullHandlesPreview.value || pullHandlesPreview.value.pull_count === 0) {
        return;
    }
    pullHandlesApplyBusy.value = true;
    pullHandlesPreviewError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                summary: PoPullHandlesSummary;
                purchase_order?: PurchaseOrder;
            };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/pull-handles`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Pull handles failed (HTTP ${res.status}).`);
        }
        pullHandlesSummary.value = res.data.data.summary ?? null;
        applyWorkflowPoResponse(res.data.data ?? {});
        await load();
    } catch (e: unknown) {
        pullHandlesPreviewError.value =
            e instanceof Error ? e.message : 'Failed to pull handles from Shopify.';
    } finally {
        pullHandlesApplyBusy.value = false;
    }
}

async function confirmExportShopifyPush(): Promise<void> {
    if (!po.value || !exportShopifyPreview.value || exportShopifyPreview.value.export_count === 0) {
        return;
    }
    exportShopifyPrepareBusy.value = true;
    exportShopifyPreviewError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                summary: PoExportShopifyPushSummary;
                purchase_order?: PurchaseOrder;
            };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/export-shopify-content/push`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Push failed (HTTP ${res.status}).`);
        }
        exportShopifyPushSummary.value = res.data.data.summary ?? res.data.data;
        if ((exportShopifyPushSummary.value?.failed ?? 0) === 0) {
            applyWorkflowPoResponse(res.data.data ?? {});
            await load();
        }
    } catch (e: unknown) {
        exportShopifyPreviewError.value =
            e instanceof Error ? e.message : 'Failed to push products to Shopify.';
    } finally {
        exportShopifyPrepareBusy.value = false;
    }
}

async function confirmSetPricesApply(): Promise<void> {
    if (!po.value || !setPricesPreview.value || setPricesPreview.value.apply_count === 0) return;
    setPricesApplyBusy.value = true;
    setPricesPreviewError.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: { purchase_order?: PurchaseOrder };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/set-prices`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Apply failed (HTTP ${res.status}).`);
        }
        applyWorkflowPoResponse(res.data.data ?? {});
        setPricesDialogOpen.value = false;
        setPricesPreview.value = null;
        await load();
    } catch (e: unknown) {
        setPricesPreviewError.value =
            e instanceof Error ? e.message : 'Failed to apply selling prices.';
    } finally {
        setPricesApplyBusy.value = false;
    }
}

async function clearStaleLatestArrivalFromWorkflow(): Promise<void> {
    if (!po.value) return;
    const ok = window.confirm(
        'Remove the latest arrival flag from products on POs older than 4 weeks (skipping products also on a PO within the last 4 weeks), and remove only the "latest arrival" tag on Shopify for those cleared? Published on Shopify and other tags are not changed.',
    );
    if (!ok) return;

    clearingStaleLatestArrival.value = true;
    workflowActionError.value = null;
    clearStaleLatestArrivalSummary.value = null;
    try {
        const res = await api.post<{
            ok: boolean;
            data: {
                purchase_orders_matched: number;
                products_cleared: number;
                cutoff_date: string;
                shopify_tags_removed: number;
                shopify_skipped_no_gid: number;
                shopify_tag_removals_failed: number;
            };
        }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-actions/clear-stale-latest-arrival`,
            {},
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as { message?: string })?.message;
            throw new Error(msg ?? `Clear failed (HTTP ${res.status}).`);
        }
        const d = res.data.data;
        let msg = `Cleared latest arrival on ${d.products_cleared} product(s) from ${d.purchase_orders_matched} PO(s) before ${d.cutoff_date}.`;
        if (d.shopify_tags_removed > 0) {
            msg += ` Removed the latest arrival tag on ${d.shopify_tags_removed} Shopify product(s).`;
        }
        if (d.shopify_skipped_no_gid > 0) {
            msg += ` ${d.shopify_skipped_no_gid} had no Shopify mirror.`;
        }
        if (d.shopify_tag_removals_failed > 0) {
            msg += ` ${d.shopify_tag_removals_failed} Shopify tag removal(s) failed.`;
        }
        clearStaleLatestArrivalSummary.value = msg;
    } catch (e: unknown) {
        workflowActionError.value =
            e instanceof Error ? e.message : 'Failed to clear stale latest arrival flags.';
    } finally {
        clearingStaleLatestArrival.value = false;
    }
}

async function applyReceivedFromChecklist(): Promise<void> {
    if (!inventoryPrepareReady.value) {
        workflowActionError.value =
            'Run Prepare first (pull Shopify qty and validate received qty).';
        return;
    }
    await applyReceivedQtyToAvailable();
    await runWorkflowVerify();
}

const checklist = computed<Record<WorkflowChecklistKey, boolean>>(() => {
    const raw = (po.value?.workflow_checklist ?? {}) as Record<string, boolean>;
    const legacy = Boolean(raw.mark_latest_arrival_and_published_on_shopify);
    return {
        import_po: Boolean(raw.import_po),
        crawl_desc_image_price: Boolean(raw.crawl_desc_image_price),
        select_and_arrange_product_images: Boolean(raw.select_and_arrange_product_images),
        set_selling_price: Boolean(raw.set_selling_price),
        ensure_all_products_have_barcode: Boolean(raw.ensure_all_products_have_barcode),
        export_to_shopify_get_handles: Boolean(raw.export_to_shopify_get_handles),
        import_handle_only: Boolean(raw.import_handle_only),
        update_product_available_with_shopify_current_inventory_quantity: Boolean(
            raw.update_product_available_with_shopify_current_inventory_quantity,
        ),
        mark_published_on_shopify: Boolean(raw.mark_published_on_shopify ?? legacy),
        mark_latest_arrival: Boolean(raw.mark_latest_arrival ?? legacy),
        import_product_available_quantity: Boolean(raw.import_product_available_quantity),
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

function poShipmentMethodLabel(method: PurchaseOrder['shipment_method']): string {
    switch (method) {
        case 'air':
            return 'Air';
        case 'sea':
            return 'Sea';
        default:
            return '—';
    }
}

function draftStatusLabel(status: PurchaseOrder['status']): string {
    switch (status) {
        case 'on_shelves':
            return 'On shelves';
        case 'received':
            return 'Received';
        case 'shipped':
            return 'Shipped';
        case 'ordered':
            return 'Ordered';
        default:
            return 'Draft';
    }
}

function openPoLines(item: PurchaseOrderItem): void {
    if (!item.product_id) return;
    poLinesProductId.value = item.product_id;
    poLinesProductSku.value = item.sku;
    poLinesProductName.value = item.product_name ?? null;
    poLinesOpen.value = true;
}

function closePoLines(): void {
    poLinesOpen.value = false;
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

    if (exportType === 'shopify_content_no_inventory') {
        const res = await api.post<{
            download_url: string;
        }>(
            '/api/v1/products/exports/shopify-content-no-inventory/prepare',
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

async function exportDraftLinesCsv(): Promise<void> {
    if (!po.value) return;
    exportDraftLinesError.value = null;
    exportDraftLinesBusy.value = true;
    try {
        const res = await api.get(`/api/v1/purchase-orders/${po.value.id}/draft-lines-export`, {
            responseType: 'blob',
            validateStatus: () => true,
        });
        if (res.status !== 200) {
            throw new Error(`Failed to export draft lines (HTTP ${res.status}).`);
        }

        const header = (res.headers as Record<string, string | undefined>)['content-disposition'];
        const filename =
            parseFilenameFromContentDisposition(header) ??
            `purchase-order-${po.value.id}-lines.csv`;
        const blob = res.data as Blob;
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    } catch (e: unknown) {
        exportDraftLinesError.value =
            e instanceof Error ? e.message : 'Failed to export draft lines.';
    } finally {
        exportDraftLinesBusy.value = false;
    }
}

async function addProductsToDraftBySku(): Promise<void> {
    if (!po.value) return;
    addDraftProductsError.value = null;
    addDraftProductsSummary.value = null;
    const skus = draftAddSkus.value
        .split(/[\s,]+/)
        .map((s) => s.trim())
        .filter((s) => s !== '');
    if (skus.length === 0) {
        addDraftProductsError.value = 'Enter at least one SKU.';
        return;
    }

    addingDraftProducts.value = true;
    try {
        const res = await api.post<{
            ok: boolean;
            requested_skus: number;
            found_products: number;
            added: number;
            skipped_existing: number;
            skipped_vendor_mismatch: number;
            skipped_not_found: number;
        }>(
            `/api/v1/purchase-orders/${po.value.id}/draft-products`,
            { skus },
            { validateStatus: () => true },
        );
        if (res.status !== 200) {
            const msg = (res.data as any)?.message as string | undefined;
            throw new Error(msg ?? `Failed to add products (HTTP ${res.status}).`);
        }

        addDraftProductsSummary.value =
            `Added ${res.data.added} line(s). ` +
            `Skipped existing: ${res.data.skipped_existing}, vendor mismatch: ${res.data.skipped_vendor_mismatch}, not found: ${res.data.skipped_not_found}.`;
        draftAddSkus.value = '';
        await load();
    } catch (e: unknown) {
        addDraftProductsError.value = e instanceof Error ? e.message : 'Failed to add products.';
    } finally {
        addingDraftProducts.value = false;
    }
}

async function patchChecklist(changes: Record<string, boolean>): Promise<void> {
    if (!po.value) return;
    checklistError.value = null;
    checklistBusy.value = true;

    const prev = po.value.workflow_checklist ?? {};
    po.value.workflow_checklist = { ...prev, ...changes };

    try {
        const res = await api.patch<{ data: PurchaseOrder }>(
            `/api/v1/purchase-orders/${po.value.id}/workflow-checklist`,
            changes,
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

async function toggleChecklist(key: WorkflowChecklistKey, next: boolean): Promise<void> {
    const changes: Record<string, boolean> = { [key]: next };
    if (key === 'select_and_arrange_product_images' && !next) {
        changes.select_and_arrange_product_images_deferred = false;
    }
    if (key === 'crawl_desc_image_price' && !next) {
        changes.crawl_desc_image_price_skipped = false;
    }
    await patchChecklist(changes);
}

async function skipCrawlStep(): Promise<void> {
    if (!po.value || checklist.value.crawl_desc_image_price) return;

    if (isPlamodVendor(po.value.vendor)) {
        const ok = window.confirm(
            'Skip crawling for this Plamod PO? New products will not get desc, images, or prices from crawl. You can run Crawl new later if needed.',
        );
        if (!ok) return;
    }

    await patchChecklist({
        crawl_desc_image_price: true,
        crawl_desc_image_price_skipped: true,
    });
}

async function deferImageCuration(): Promise<void> {
    if (!po.value || checklist.value.select_and_arrange_product_images) return;

    const ok = window.confirm(
        'Defer image curation for this PO? You can add and arrange product photos later. This checks off the step so the rest of the workflow can continue.',
    );
    if (!ok) return;

    await patchChecklist({
        select_and_arrange_product_images: true,
        select_and_arrange_product_images_deferred: true,
    });
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
        const apply = data.apply ?? data;
        const productsUpdated = Number(apply.products_updated ?? 0);
        const totalAdded = Number(apply.total_added ?? 0);
        const skippedNonPositive = Number(apply.skipped_non_positive_qty ?? 0);
        const skippedMissingProductId = Number(apply.skipped_missing_product_id ?? 0);
        applyReceivedSummary.value =
            `Added ${totalAdded} to available qty across ${productsUpdated} product(s). ` +
            `Skipped ${skippedNonPositive} line(s) with qty_received <= 0 and ${skippedMissingProductId} line(s) missing linked product.`;
        applyWorkflowPoResponse(data);
        await load();
    } catch (e: unknown) {
        applyReceivedError.value = e instanceof Error ? e.message : 'Failed to apply received qty.';
    } finally {
        applyingReceivedToAvailable.value = false;
    }
}
const draft = reactive<{
    vendor: string;
    supplier_order_id: string;
    shipment_method: '' | 'air' | 'sea';
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
    supplier_order_id: '',
    shipment_method: '',
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

const hasAnyQtyReceivedEntered = computed<boolean>(() => {
    return (po.value?.items ?? []).some((it) => it.qty_received !== null);
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
    // Use received totals when qty_received has been entered (including zero); otherwise use ordered totals.
    if (hasAnyQtyReceivedEntered.value) return totalUnitsReceived.value;
    return po.value?.items.reduce((sum, it) => sum + (it.qty_ordered ?? 0), 0) ?? 0;
});

function qtyForAllocation(item: PurchaseOrderItem): number {
    return item.qty_received !== null ? item.qty_received : (item.qty_ordered ?? 0);
}

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
            const qty = qtyForAllocation(it);
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
            const qty = qtyForAllocation(it);
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
            draft.supplier_order_id = po.value.supplier_order_id ?? '';
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
            draft.shipment_method = po.value.shipment_method ?? '';
        }
    } catch {
        error.value = 'Failed to load purchase order.';
    } finally {
        loading.value = false;
        if (po.value) {
            await runWorkflowVerify();
        }
    }
}

function startEdit(): void {
    if (!po.value) return;
    editOpen.value = true;
    draft.vendor = po.value.vendor ?? '';
    draft.supplier_order_id = po.value.supplier_order_id ?? '';
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
    draft.shipment_method = po.value.shipment_method ?? '';
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
            supplier_order_id:
                draft.supplier_order_id.trim() === '' ? null : draft.supplier_order_id.trim(),
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
            shipment_method: draft.shipment_method === '' ? null : draft.shipment_method,
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

function startBarcodeEdit(itemId: number, current: string | null): void {
    editingBarcodeItemId.value = itemId;
    if (barcodeDrafts[itemId] === undefined) {
        barcodeDrafts[itemId] = current ?? '';
    }
}

function updateBarcodeDraft(itemId: number, value: string): void {
    barcodeDrafts[itemId] = value;
}

function commitBarcodeEdit(itemId: number): void {
    if (editingBarcodeItemId.value !== itemId) return;
    editingBarcodeItemId.value = null;
    const value = barcodeDrafts[itemId] ?? '';
    delete barcodeDrafts[itemId];
    void saveBarcode(itemId, value);
}

function setProductBarcodeLocal(productId: string, barcode: string | null): void {
    if (!po.value) return;
    for (const item of po.value.items) {
        if (item.product_id === productId) {
            item.product_barcode = barcode;
        }
    }
}

async function saveBarcode(itemId: number, value: string | null): Promise<void> {
    if (!po.value) return;
    itemQtyError.value = null;

    const row = po.value.items.find((it) => it.id === itemId);
    if (!row) {
        itemQtyError.value = 'Failed to save barcode.';
        return;
    }

    const productId = typeof row.product_id === 'string' ? row.product_id.trim() : '';
    if (productId === '') {
        itemQtyError.value = 'Cannot save barcode for a line without product id.';
        return;
    }

    const previous = row.product_barcode ?? null;
    const barcode = value !== null && value.trim() !== '' ? value.trim() : null;
    savingBarcodeProductId.value = productId;

    try {
        setProductBarcodeLocal(productId, barcode);

        const res = await api.patch(
            `/api/v1/products/${productId}/barcode`,
            { barcode },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            setProductBarcodeLocal(productId, previous);
            itemQtyError.value = (res.data as any)?.message ?? 'Failed to save barcode.';
            return;
        }

        const saved = (res.data as any)?.data?.barcode as string | null | undefined;
        setProductBarcodeLocal(productId, saved ?? null);
    } catch {
        setProductBarcodeLocal(productId, previous);
        itemQtyError.value = 'Failed to save barcode.';
    } finally {
        if (savingBarcodeProductId.value === productId) {
            savingBarcodeProductId.value = null;
        }
    }
}

function parseUnitCostOrNull(value: string): number | null {
    const v = value.trim();
    if (v === '') return null;
    const n = parseMoney(v);
    if (n === null || n < 0) return null;
    return n;
}

function startUnitCostEdit(itemId: number, current: string | null): void {
    editingUnitCostId.value = itemId;
    if (unitCostDrafts[itemId] === undefined) {
        unitCostDrafts[itemId] = current ?? '';
    }
}

function updateUnitCostDraft(itemId: number, value: string): void {
    unitCostDrafts[itemId] = value;
}

function commitUnitCostEdit(itemId: number): void {
    if (editingUnitCostId.value !== itemId) return;
    editingUnitCostId.value = null;
    const value = unitCostDrafts[itemId] ?? '';
    delete unitCostDrafts[itemId];
    void saveUnitCost(itemId, value);
}

async function saveUnitCost(itemId: number, value: string): Promise<void> {
    if (!po.value) return;
    itemQtyError.value = null;
    savingUnitCost.value = itemId;

    const row = po.value.items.find((it) => it.id === itemId);
    const previous = row?.unit_cost ?? null;
    const next = parseUnitCostOrNull(value);

    if (value.trim() !== '' && next === null) {
        itemQtyError.value = 'Unit cost must be a non-negative number.';
        savingUnitCost.value = null;
        return;
    }

    try {
        if (row) row.unit_cost = next === null ? null : next.toFixed(2);

        const res = await api.patch(
            `/api/v1/purchase-order-items/${itemId}`,
            { unit_cost: next },
            { validateStatus: () => true },
        );

        if (res.status < 200 || res.status >= 300) {
            if (row) row.unit_cost = previous;
            itemQtyError.value = (res.data as any)?.message ?? 'Failed to save unit cost.';
            return;
        }

        const saved = (res.data as any)?.data as PurchaseOrderItem | undefined;
        if (saved && row) {
            row.unit_cost = saved.unit_cost ?? null;
        }
    } catch {
        if (row) row.unit_cost = previous;
        itemQtyError.value = 'Failed to save unit cost.';
    } finally {
        savingUnitCost.value = null;
    }
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
    await startPoImport('replace');
}

async function importMoreCsvIntoPo(): Promise<void> {
    await startPoImport('append');
}

function buildPoImportFormData(mode: 'replace' | 'append'): FormData {
    if (!po.value || !reimportFile.value) {
        throw new Error('Missing PO or file');
    }

    const fd = new FormData();
    fd.append('file', reimportFile.value);
    fd.append('vendor', po.value.vendor);
    fd.append('purchase_order_uuid', po.value.id);
    fd.append('import_mode', mode);
    if (mode === 'replace' && reimportResetReceipt.value) {
        fd.append('reset_receipt_before_reimport', '1');
    }
    if (importProductTotal.value.trim() !== '') {
        fd.append('product_total', importProductTotal.value.trim());
    }
    if (importProductTotalIncludesFees.value) {
        fd.append('product_total_includes_fees', '1');
    }
    if (importShippingTotal.value.trim() !== '' && !importProductTotalIncludesFees.value) {
        fd.append('shipping_total', importShippingTotal.value.trim());
    }
    if (po.value.shipment_method) {
        fd.append('shipment_method', po.value.shipment_method);
    }

    return fd;
}

function closePoImportPreview(): void {
    importPreviewOpen.value = false;
    importPreviewLoading.value = false;
    importPreviewError.value = null;
    importPreview.value = null;
}

async function loadPoImportPreview(mode: 'replace' | 'append'): Promise<void> {
    importPreviewError.value = null;
    importPreview.value = null;
    importPreviewLoading.value = true;
    importPreviewOpen.value = true;
    pendingImportMode.value = mode;

    try {
        const fd = buildPoImportFormData(mode);
        const res = await api.post<PoImportPreview>('/api/v1/purchase-orders/import/preview', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        importPreview.value = res.data;
    } catch (e: unknown) {
        const anyErr = e as any;
        importPreviewError.value =
            anyErr?.response?.data?.message ??
            'Preview failed. Check the file format and try again.';
    } finally {
        importPreviewLoading.value = false;
    }
}

async function startPoImport(mode: 'replace' | 'append'): Promise<void> {
    if (!po.value) return;
    reimportError.value = null;

    if (!reimportFile.value) {
        reimportError.value = 'Please choose a CSV or XLSX file.';
        return;
    }

    if (isPmBrokerVendor.value) {
        await loadPoImportPreview(mode);
        return;
    }

    await executePoImport(mode);
}

async function confirmPoImportPreview(): Promise<void> {
    await executePoImport(pendingImportMode.value);
}

async function executePoImport(mode: 'replace' | 'append'): Promise<void> {
    if (!po.value) return;
    reimportError.value = null;

    const resetNote =
        mode === 'replace' && reimportResetReceipt.value
            ? '\n\nYou asked to clear PO receipt data first: PO-linked inventory lots and movement rows will be removed and qty received cleared on all lines before lines are replaced. Product available qty is not adjusted.'
            : '';

    const msg =
        mode === 'replace'
            ? po.value.counts.items > 0
                ? `Re-import this file into the current PO? This will REPLACE ${po.value.counts.items} existing line item(s) and update product barcodes/names.${resetNote}`
                : `Re-import this file into the current PO? This will update product barcodes/names.${resetNote}`
            : `Import additional products into this PO? This will ADD line items and keep the existing ${po.value.counts.items} line item(s). PO header totals from this import will be combined with the current PO totals.`;

    if (!window.confirm(msg)) return;

    if (mode === 'replace') reimporting.value = true;
    else importMoreing.value = true;

    try {
        const fd = buildPoImportFormData(mode);

        const res = await api.post('/api/v1/purchase-orders/import', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
            validateStatus: () => true,
        });

        if (res.status !== 200) {
            const message = (res.data as any)?.message as string | undefined;
            const issues = (res.data as any)?.issues as any;
            reimportError.value = formatPoImportError(message ?? 'Import failed.', issues);
            importPreviewError.value = reimportError.value;
            return;
        }

        reimportFile.value = null;
        reimportResetReceipt.value = false;
        importProductTotal.value = '';
        importShippingTotal.value = '';
        importProductTotalIncludesFees.value = false;
        closePoImportPreview();
        await load();
    } catch {
        reimportError.value = mode === 'replace' ? 'Re-import failed.' : 'Import more failed.';
        importPreviewError.value = reimportError.value;
    } finally {
        if (mode === 'replace') reimporting.value = false;
        else importMoreing.value = false;
    }
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
                            <span class="font-medium">Supplier order ID:</span>
                            {{ po.supplier_order_id ?? '—' }}
                        </div>
                        <div>
                            <span class="font-medium">Created:</span>
                            {{ formatTorontoDateTime(po.created_at) }}
                        </div>
                        <div>
                            <span class="font-medium">Status:</span>
                            {{ draftStatusLabel(po.status) }}
                        </div>
                        <div>
                            <span class="font-medium">Shipment:</span>
                            {{ poShipmentMethodLabel(po.shipment_method) }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="exportDraftLinesBusy"
                            @click="exportDraftLinesCsv"
                        >
                            {{ exportDraftLinesBusy ? 'Exporting…' : 'Export order CSV' }}
                        </button>
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
                <p v-if="exportDraftLinesError" class="mt-2 text-sm text-rose-700">
                    {{ exportDraftLinesError }}
                </p>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="text-xs font-semibold text-slate-800">
                        Re-import file into this PO
                    </div>
                    <div class="mt-2 grid grid-cols-1 gap-3 lg:grid-cols-6 lg:items-end">
                        <div class="lg:col-span-2">
                            <label class="text-xs font-medium text-slate-700">
                                {{
                                    importProductTotalIncludesFees
                                        ? 'Total paid (CAD)'
                                        : 'Product total (CAD)'
                                }}
                            </label>
                            <input
                                v-model="importProductTotal"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                :placeholder="
                                    importProductTotalIncludesFees
                                        ? 'Product + shipping'
                                        : 'Optional'
                                "
                                :disabled="reimporting || importMoreing || importPreviewLoading"
                            />
                            <label
                                v-if="isPmBrokerVendor"
                                class="mt-2 flex items-start gap-2 text-[11px] text-slate-600"
                            >
                                <input
                                    v-model="importProductTotalIncludesFees"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-slate-300"
                                    :disabled="reimporting || importMoreing || importPreviewLoading"
                                />
                                <span
                                    >Total includes product and shipping (split using invoice
                                    HKD)</span
                                >
                            </label>
                        </div>
                        <div class="lg:col-span-1">
                            <label class="text-xs font-medium text-slate-700">Shipping total</label>
                            <input
                                v-model="importShippingTotal"
                                type="text"
                                inputmode="decimal"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm disabled:bg-slate-50 disabled:text-slate-500"
                                placeholder="Optional"
                                :disabled="
                                    importProductTotalIncludesFees ||
                                    reimporting ||
                                    importMoreing ||
                                    importPreviewLoading
                                "
                            />
                        </div>
                        <div class="lg:col-span-3">
                            <label class="text-xs font-medium text-slate-700"
                                >CSV / XLSX file</label
                            >
                            <input
                                type="file"
                                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                :disabled="reimporting || importMoreing || importPreviewLoading"
                                @change="onReimportFileChange"
                            />
                            <label
                                class="mt-2 flex cursor-pointer items-start gap-2 text-[11px] text-slate-700"
                            >
                                <input
                                    v-model="reimportResetReceipt"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900"
                                    :disabled="reimporting || importMoreing || importPreviewLoading"
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
                                existing lines. For Dspiae/Stedi PM invoices, use Preview import
                                first. Import more combines product, shipping, and vendor totals
                                from each import into the PO header.
                            </div>
                        </div>
                        <div class="lg:col-span-1 space-y-2">
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="reimporting || importMoreing || importPreviewLoading"
                                @click="reimportCsvIntoPo"
                            >
                                {{
                                    reimporting
                                        ? 'Re-importing…'
                                        : isPmBrokerVendor
                                          ? 'Preview re-import'
                                          : 'Re-import'
                                }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="reimporting || importMoreing || importPreviewLoading"
                                @click="importMoreCsvIntoPo"
                            >
                                {{
                                    importMoreing
                                        ? 'Importing…'
                                        : importPreviewLoading
                                          ? 'Previewing…'
                                          : isPmBrokerVendor
                                            ? 'Preview import more'
                                            : 'Import more'
                                }}
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

                <div
                    v-if="po.status === 'draft'"
                    class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3"
                >
                    <div class="text-xs font-semibold text-slate-800">
                        Draft PO: add products by SKU
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        Paste SKUs separated by comma, space, or newline. Existing lines are
                        skipped.
                    </p>
                    <textarea
                        v-model="draftAddSkus"
                        rows="4"
                        class="mt-2 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                        placeholder="SKU-001&#10;SKU-002"
                    />
                    <div class="mt-2">
                        <button
                            type="button"
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="addingDraftProducts"
                            @click="addProductsToDraftBySku"
                        >
                            {{ addingDraftProducts ? 'Adding…' : 'Add products' }}
                        </button>
                    </div>
                    <p v-if="addDraftProductsError" class="mt-2 text-sm text-rose-700">
                        {{ addDraftProductsError }}
                    </p>
                    <p v-if="addDraftProductsSummary" class="mt-2 text-sm text-emerald-800">
                        {{ addDraftProductsSummary }}
                    </p>
                </div>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold text-slate-800">
                                Workflow checklist
                            </div>
                            <div class="mt-1 text-xs text-slate-600">
                                This checklist is saved on this PO. Checked steps stay checked; use
                                Re-verify to auto-check completed steps. Only
                                <span class="font-medium">Plamod</span> POs auto-complete crawl when
                                PDP data exists; use <span class="font-medium">Skip</span> or
                                <span class="font-medium">Crawl new</span> with confirmation when
                                needed. Check
                                <span class="font-medium">Set/review selling price</span> only after
                                you review and approve prices in the dialog.
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-60"
                                :disabled="checklistBusy || workflowVerifyBusy || !po"
                                @click="runWorkflowVerify"
                            >
                                {{ workflowVerifyBusy ? 'Verifying…' : 'Re-verify' }}
                            </button>
                            <div v-if="checklistBusy" class="text-xs text-slate-600">Saving…</div>
                        </div>
                    </div>

                    <div class="mt-3 space-y-2">
                        <div
                            v-for="row in checklistLabels"
                            :key="row.key"
                            class="flex items-center gap-2"
                        >
                            <label
                                class="flex min-w-0 flex-1 items-center gap-2 text-sm text-slate-800"
                            >
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 shrink-0 rounded border-slate-300"
                                    :disabled="checklistBusy"
                                    :checked="checklist[row.key]"
                                    @change="
                                        toggleChecklist(
                                            row.key,
                                            ($event.target as HTMLInputElement).checked,
                                        )
                                    "
                                />
                                <span class="min-w-0">{{ row.label }}</span>
                            </label>
                            <button
                                v-if="row.key === 'mark_latest_arrival'"
                                type="button"
                                class="shrink-0 rounded-md border border-amber-200 bg-white px-2 py-1 text-xs font-medium text-amber-900 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="checklistBusy || clearingStaleLatestArrival"
                                data-testid="workflow-clear-stale-latest-arrival"
                                title="Remove latest arrival from products on POs older than 4 weeks"
                                @click="clearStaleLatestArrivalFromWorkflow"
                            >
                                {{ clearingStaleLatestArrival ? 'Clearing…' : 'Clear old latest' }}
                            </button>
                            <button
                                v-if="workflowRowButtonLabel(row.key)"
                                type="button"
                                class="shrink-0 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                :title="
                                    row.key === 'mark_published_on_shopify'
                                        ? 'Sets published_on_shopify in the ERP only until push'
                                        : row.key === 'mark_latest_arrival'
                                          ? 'Sets latest_arrival in the ERP for non-tools; push adds the Shopify tag'
                                          : row.key ===
                                              'update_product_available_with_shopify_current_inventory_quantity'
                                            ? 'Validates qty received on all lines. If Shopify mirror data is stale, asks whether to pull fresh inventory for PO SKUs. Run before Apply received.'
                                            : undefined
                                "
                                :disabled="
                                    checklistBusy ||
                                    Boolean(workflowActionBusy[row.key]) ||
                                    (row.key === 'export_to_shopify_get_handles' &&
                                        !poHasProducts) ||
                                    (row.key === 'crawl_desc_image_price' && !poHasProducts)
                                "
                                @click="onWorkflowRowAction(row.key)"
                            >
                                <template
                                    v-if="
                                        row.key ===
                                        'update_product_available_with_shopify_current_inventory_quantity'
                                    "
                                >
                                    {{ workflowActionBusy[row.key] ? 'Preparing…' : 'Prepare' }}
                                </template>
                                <template v-else>
                                    {{
                                        workflowActionBusy[row.key]
                                            ? 'Working…'
                                            : workflowRowButtonLabel(row.key)
                                    }}
                                </template>
                            </button>
                            <button
                                v-if="
                                    row.key ===
                                    'update_product_available_with_shopify_current_inventory_quantity'
                                "
                                type="button"
                                class="shrink-0 rounded-md border border-emerald-200 bg-white px-2 py-1 text-xs font-medium text-emerald-800 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="
                                    !inventoryPrepareReady ||
                                    applyingReceivedToAvailable ||
                                    !poHasProducts
                                "
                                @click="applyReceivedFromChecklist"
                            >
                                {{ applyingReceivedToAvailable ? 'Applying…' : 'Apply received' }}
                            </button>
                            <button
                                v-if="
                                    row.key === 'crawl_desc_image_price' &&
                                    !checklist.crawl_desc_image_price
                                "
                                type="button"
                                class="shrink-0 rounded-md border border-amber-200 bg-white px-2 py-1 text-xs font-medium text-amber-900 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="checklistBusy"
                                data-testid="workflow-skip-crawl"
                                @click="skipCrawlStep"
                            >
                                Skip
                            </button>
                            <button
                                v-if="
                                    row.key === 'select_and_arrange_product_images' &&
                                    !checklist.select_and_arrange_product_images
                                "
                                type="button"
                                class="shrink-0 rounded-md border border-amber-200 bg-white px-2 py-1 text-xs font-medium text-amber-900 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="checklistBusy"
                                data-testid="workflow-defer-image-curation"
                                @click="deferImageCuration"
                            >
                                Defer
                            </button>
                        </div>
                    </div>

                    <p v-if="inventoryPrepareSummary" class="mt-2 text-xs text-emerald-800">
                        {{ inventoryPrepareSummary }}
                    </p>
                    <p v-if="clearStaleLatestArrivalSummary" class="mt-2 text-xs text-amber-900">
                        {{ clearStaleLatestArrivalSummary }}
                    </p>
                    <p v-if="workflowActionError" class="mt-3 text-sm text-rose-700">
                        {{ workflowActionError }}
                    </p>
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
                        <div class="lg:col-span-2">
                            <label class="text-xs font-medium text-slate-700"
                                >Supplier order ID</label
                            >
                            <input
                                v-model="draft.supplier_order_id"
                                type="text"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700">Shipment</label>
                            <select
                                v-model="draft.shipment_method"
                                class="mt-1 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                            >
                                <option value="">—</option>
                                <option value="air">Air</option>
                                <option value="sea">Sea</option>
                            </select>
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
                                <th class="px-2 py-1 text-right">Available</th>
                                <th class="px-2 py-1 text-right">Maintain</th>
                                <th class="px-2 py-1 text-right">Not arrived</th>
                                <th class="px-2 py-1 text-right">Reorder</th>
                                <th class="px-2 py-1 text-right">Total ordered</th>
                                <th class="px-2 py-1 text-right">Total sold</th>
                                <th class="px-2 py-1 text-right">Selling</th>
                                <th class="px-2 py-1 text-right">Latest landed</th>
                                <th class="px-2 py-1 text-right">Multiplier</th>
                                <th class="px-2 py-1 text-right">PO Lines</th>
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
                                                <input
                                                    :data-testid="`barcode-input-${it.id}`"
                                                    class="w-56 rounded border border-slate-200 bg-white px-1.5 py-0.5 font-mono text-[11px] text-slate-700 disabled:bg-slate-50 disabled:text-slate-400"
                                                    type="text"
                                                    :value="
                                                        barcodeDrafts[it.id] ??
                                                        it.product_barcode ??
                                                        ''
                                                    "
                                                    :disabled="
                                                        !it.product_id ||
                                                        savingBarcodeProductId === it.product_id
                                                    "
                                                    placeholder="—"
                                                    @focus="
                                                        startBarcodeEdit(it.id, it.product_barcode)
                                                    "
                                                    @input="
                                                        updateBarcodeDraft(
                                                            it.id,
                                                            ($event.target as HTMLInputElement)
                                                                .value,
                                                        )
                                                    "
                                                    @keydown.enter.prevent="
                                                        commitBarcodeEdit(it.id)
                                                    "
                                                    @blur="commitBarcodeEdit(it.id)"
                                                />
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
                                    <input
                                        class="w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-xs tabular-nums text-slate-900 disabled:bg-slate-50 disabled:text-slate-400"
                                        type="text"
                                        inputmode="decimal"
                                        :value="
                                            unitCostDrafts[it.id] ??
                                            formatMoney2OrEmpty(it.unit_cost)
                                        "
                                        :disabled="savingUnitCost === it.id"
                                        :data-testid="`unit-cost-input-${it.id}`"
                                        @focus="startUnitCostEdit(it.id, it.unit_cost)"
                                        @input="
                                            updateUnitCostDraft(
                                                it.id,
                                                ($event.target as HTMLInputElement).value,
                                            )
                                        "
                                        @keydown.enter.prevent="commitUnitCostEdit(it.id)"
                                        @blur="commitUnitCostEdit(it.id)"
                                    />
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
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.available ?? '—' }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.maintain ?? '—' }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.not_arrived ?? 0 }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.reorder ?? 0 }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.total_ordered ?? 0 }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.total_sold ?? 0 }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.selling_price ? formatMoney2(it.selling_price) : '—' }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{
                                        it.latest_landed_unit_cost
                                            ? formatMoney2(it.latest_landed_unit_cost)
                                            : '—'
                                    }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ it.multiplier ? `${it.multiplier}x` : '—' }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    <button
                                        v-if="it.product_id"
                                        type="button"
                                        class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50"
                                        @click="openPoLines(it)"
                                    >
                                        PO Lines
                                    </button>
                                    <span v-else>—</span>
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
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
                                <td class="px-2 py-2"></td>
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
    <PoWorkflowSetPricesDialog
        :open="setPricesDialogOpen"
        :busy="setPricesApplyBusy || setPricesPreviewLoading"
        :preview="setPricesPreview"
        :error="setPricesPreviewError"
        @cancel="closeSetPricesDialog"
        @confirm="confirmSetPricesApply"
    />
    <PoWorkflowExportShopifyDialog
        :open="exportShopifyDialogOpen"
        :busy="exportShopifyPrepareBusy || exportShopifyPreviewLoading"
        :preview="exportShopifyPreview"
        :push-summary="exportShopifyPushSummary"
        :error="exportShopifyPreviewError"
        @cancel="closeExportShopifyDialog"
        @confirm="confirmExportShopifyPush"
    />
    <PoWorkflowPullHandlesDialog
        :open="pullHandlesDialogOpen"
        :busy="pullHandlesApplyBusy || pullHandlesPreviewLoading"
        :preview="pullHandlesPreview"
        :pull-summary="pullHandlesSummary"
        :error="pullHandlesPreviewError"
        @cancel="closePullHandlesDialog"
        @confirm="confirmPullHandles"
    />
    <PoWorkflowPushInventoryDialog
        :open="pushInventoryDialogOpen"
        :busy="pushInventoryPushBusy || pushInventoryPreviewLoading"
        :preview="pushInventoryPreview"
        :push-summary="pushInventoryPushSummary"
        :error="pushInventoryPreviewError"
        @cancel="closePushInventoryDialog"
        @confirm="confirmPushInventory"
    />
    <PoImportPreviewDialog
        :open="importPreviewOpen"
        :busy="reimporting || importMoreing || importPreviewLoading"
        :preview="importPreview"
        :error="importPreviewError"
        @cancel="closePoImportPreview"
        @confirm="confirmPoImportPreview"
    />
    <ProductPoLinesDrawer
        :open="poLinesOpen"
        :product-id="poLinesProductId"
        :product-sku="poLinesProductSku"
        :product-name="poLinesProductName"
        @close="closePoLines"
    />
</template>

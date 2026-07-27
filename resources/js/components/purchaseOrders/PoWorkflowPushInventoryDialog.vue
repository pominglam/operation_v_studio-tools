<script setup lang="ts">
import { computed, ref } from 'vue';

export type PoPushInventoryPreviewRow = {
    product_uuid: string;
    sku: string;
    description: string;
    product_type: string | null;
    type_rank: number;
    product_created_at: string | null;
    handle: string | null;
    erp_available_qty: number;
    erp_hold_qty: number;
    shopify_push_qty: number;
    shopify_available_qty: number | null;
    selling_price: string | null;
    push_action: 'create' | 'update';
    push_eligible: boolean;
    skip_reason: 'missing_sku' | 'missing_selling_price' | 'missing_shopify_mirror' | null;
};

export type PoPushInventoryPreview = {
    location_gid: string;
    location_name: string | null;
    write_products_scope_ok: boolean;
    write_inventory_scope_ok: boolean;
    write_publications_scope_ok: boolean;
    images_enabled: boolean;
    tunnel_url: string | null;
    products: PoPushInventoryPreviewRow[];
    push_count: number;
    product_uuids: string[];
};

export type PoPushInventoryCollectionReorder = {
    attempted: boolean;
    collection_gid: string | null;
    product_count: number;
    moves_sent: number;
    job_id: string | null;
    skipped_reason: string | null;
};

export type PoPushInventorySummary = {
    location_gid: string;
    location_name: string | null;
    created: number;
    updated: number;
    failed: number;
    skipped: number;
    images_enabled: boolean;
    errors: Array<{ sku: string; message: string }>;
    collection_reorder?: PoPushInventoryCollectionReorder;
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    preview: PoPushInventoryPreview | null;
    pushSummary: PoPushInventorySummary | null;
    error: string | null;
    receivedDate: string | null;
    progressPercent?: number;
    phaseLabel?: string;
}>();

const hasReceivedDate = computed(() => (props.receivedDate?.trim() ?? '') !== '');

const canConfirmPush = computed(() => {
    if (!hasReceivedDate.value || !props.preview) {
        return false;
    }

    return (
        props.preview.write_products_scope_ok &&
        props.preview.write_inventory_scope_ok &&
        props.preview.push_count > 0
    );
});

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm'): void;
}>();

function formatQty(value: number | null | undefined): string {
    if (value == null) return '—';
    return String(value);
}

function skipLabel(reason: PoPushInventoryPreviewRow['skip_reason']): string {
    switch (reason) {
        case 'missing_sku':
            return 'Missing SKU';
        case 'missing_selling_price':
            return 'Missing selling price';
        case 'missing_shopify_mirror':
            return 'Not in Shopify mirror';
        default:
            return 'Skipped';
    }
}

const copyNamesFeedback = ref<'idle' | 'copied' | 'failed'>('idle');

function productNamesClipboardText(): string {
    if (!props.preview) {
        return '';
    }

    return props.preview.products.map((row) => row.description).join('\n');
}

async function copyProductNames(): Promise<void> {
    const text = productNamesClipboardText();
    if (text === '') {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
        copyNamesFeedback.value = 'copied';
    } catch {
        copyNamesFeedback.value = 'failed';
    }

    window.setTimeout(() => {
        copyNamesFeedback.value = 'idle';
    }, 2000);
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
            <div
                class="flex max-h-[85vh] w-full max-w-7xl flex-col rounded-lg bg-white shadow-xl"
            >
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">
                        Push to Shopify — Latest Arrivals order
                    </div>
                    <div v-if="preview" class="mt-1 text-xs text-slate-600">
                        This PO preview sorts by grade (PG → Mega → MG
                        <span class="font-semibold text-slate-900">(MGEX first)</span> → RE → Full
                        Mechanics → RG → HGUC → HG → SD/BB →
                        <span class="font-semibold text-slate-900"
                            >30MM → 30MF → 30MS → Entry Grade → Pokemon → Figure-rise</span
                        >, newest within each grade). The storefront collection groups by PO
                        (newest first; multi-PO products use their newest PO), then the same grade
                        order within each received PO (unreceived POs are ignored on the
                        storefront). Syncs title, description, images, tags, price,
                        status, sales channels (all publications when published), and available
                        inventory for
                        <span class="font-semibold text-slate-900">{{ preview.push_count }}</span>
                        product(s) at
                        <span class="font-semibold text-slate-900">{{
                            preview.location_name ?? preview.location_gid
                        }}</span
                        >.
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="error" class="mb-3 text-sm text-rose-700">{{ error }}</p>

                    <p
                        v-if="preview && !hasReceivedDate"
                        class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"
                        data-testid="push-inventory-missing-received-date"
                    >
                        Set <span class="font-semibold">Received date</span> on this PO before
                        pushing. Without it, products can sync to Shopify but stay at the bottom of
                        Latest Arrivals — unreceived POs are ignored for storefront ordering.
                    </p>

                    <div
                        v-if="busy && !pushSummary && (phaseLabel || (progressPercent ?? 0) > 0)"
                        class="mb-3"
                    >
                        <div class="mb-1 flex items-center justify-between text-xs text-slate-600">
                            <span>{{ phaseLabel || 'Working…' }}</span>
                            <span>{{ progressPercent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-200">
                            <div
                                class="h-full rounded-full bg-slate-900 transition-all duration-300"
                                :style="{ width: `${Math.max(0, Math.min(100, progressPercent ?? 0))}%` }"
                            />
                        </div>
                    </div>

                    <div
                        v-if="pushSummary"
                        class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                    >
                        Created {{ pushSummary.created }}, updated {{ pushSummary.updated }} on
                        Shopify.
                        <span v-if="pushSummary.skipped > 0">
                            {{ pushSummary.skipped }} skipped.
                        </span>
                        <span v-if="pushSummary.failed > 0">
                            {{ pushSummary.failed }} failed.
                        </span>
                        <span
                            v-if="pushSummary.collection_reorder?.attempted"
                            class="mt-1 block"
                        >
                            Latest Arrivals collection reorder queued ({{
                                pushSummary.collection_reorder.moves_sent
                            }}
                            moves).
                        </span>
                        <span
                            v-else-if="pushSummary.collection_reorder?.skipped_reason"
                            class="mt-1 block text-amber-900"
                        >
                            Collection order not updated:
                            {{ pushSummary.collection_reorder.skipped_reason }}
                        </span>
                    </div>

                    <template v-if="preview">
                        <p
                            v-if="!preview.write_products_scope_ok"
                            class="mb-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800"
                        >
                            Missing <span class="font-semibold">write_products</span> in
                            SHOPIFY_OAUTH_SCOPES. Re-install OAuth after adding it.
                        </p>
                        <p
                            v-else-if="!preview.write_inventory_scope_ok"
                            class="mb-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800"
                        >
                            Missing <span class="font-semibold">write_inventory</span> in
                            SHOPIFY_OAUTH_SCOPES. Re-install OAuth after adding it.
                        </p>
                        <p
                            v-else-if="!preview.write_publications_scope_ok"
                            class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                        >
                            Missing <span class="font-semibold">read_publications</span> and
                            <span class="font-semibold">write_publications</span> in
                            SHOPIFY_OAUTH_SCOPES. Re-install OAuth after adding them — otherwise
                            products may show <span class="font-semibold">0 channels</span> in
                            Shopify.
                        </p>
                        <p
                            v-else-if="!preview.images_enabled"
                            class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                        >
                            Image tunnel is off — it will be started automatically for this push
                            and restored afterward.
                        </p>

                        <div class="mb-2 flex items-center justify-end">
                            <button
                                type="button"
                                class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="preview.products.length === 0"
                                @click="copyProductNames"
                            >
                                {{
                                    copyNamesFeedback === 'copied'
                                        ? 'Copied!'
                                        : copyNamesFeedback === 'failed'
                                          ? 'Copy failed'
                                          : 'Copy product names'
                                }}
                            </button>
                        </div>

                        <div class="overflow-x-auto rounded-md border border-slate-200">
                            <table class="min-w-full table-fixed divide-y divide-slate-200 text-xs">
                                <thead class="bg-slate-50 text-left text-slate-600">
                                    <tr>
                                        <th class="w-[5.5rem] px-3 py-2 font-medium">SKU</th>
                                        <th class="min-w-[18rem] px-3 py-2 font-medium">Product</th>
                                        <th class="w-[4.5rem] px-3 py-2 font-medium">Price</th>
                                        <th class="w-[4.5rem] px-3 py-2 font-medium">Available</th>
                                        <th class="w-[3.5rem] px-3 py-2 font-medium">Hold</th>
                                        <th class="w-[4.5rem] px-3 py-2 font-medium">Push qty</th>
                                        <th class="w-[5rem] px-3 py-2 font-medium">Shopify qty</th>
                                        <th class="w-[5.5rem] px-3 py-2 font-medium">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white text-slate-800">
                                    <tr
                                        v-for="row in preview.products"
                                        :key="row.product_uuid"
                                    >
                                        <td class="px-3 py-2 align-top font-mono">{{ row.sku }}</td>
                                        <td
                                            class="px-3 py-2 align-top whitespace-normal break-words leading-snug"
                                        >
                                            {{ row.description }}
                                        </td>
                                        <td class="px-3 py-2 align-top">{{ row.selling_price ?? '—' }}</td>
                                        <td class="px-3 py-2 align-top">
                                            {{ formatQty(row.erp_available_qty) }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            {{ formatQty(row.erp_hold_qty) }}
                                        </td>
                                        <td class="px-3 py-2 align-top font-medium text-slate-900">
                                            {{ formatQty(row.shopify_push_qty) }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            {{ formatQty(row.shopify_available_qty) }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <span
                                                v-if="row.push_eligible"
                                                class="font-medium text-emerald-800"
                                                >{{ row.push_action }}</span
                                            >
                                            <span v-else class="text-amber-800">{{
                                                skipLabel(row.skip_reason)
                                            }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
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
                        :disabled="busy || !canConfirmPush"
                        :title="
                            !hasReceivedDate
                                ? 'Set Received date on this PO before pushing to Shopify'
                                : undefined
                        "
                        data-testid="push-inventory-confirm"
                        @click="emit('confirm')"
                    >
                        {{ busy ? (phaseLabel || 'Pushing…') : 'Push to Shopify' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '../../lib/api';
import {
    applyPushOptionsToPreview,
    hasAnyPushOption,
    type BulkPushShopifyPreview,
    type BulkPushShopifyPreviewBase,
    type BulkPushShopifyPreviewRow,
    type ShopifyProductPushOptions,
} from '../../lib/bulkPushShopifyPreview';

export type { ShopifyProductPushOptions, BulkPushShopifyPreview, BulkPushShopifyPreviewRow };

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    selectedIds: string[];
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', payload: { pushOptions: ShopifyProductPushOptions; preview: BulkPushShopifyPreview }): void;
}>();

const defaultPushOptions = (): ShopifyProductPushOptions => ({
    info: false,
    images: false,
    quantities: false,
    price: false,
    publish_status: false,
    sales_channels: false,
});

const pushOptions = ref<ShopifyProductPushOptions>(defaultPushOptions());

const previewBase = ref<BulkPushShopifyPreviewBase | null>(null);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);

const preview = computed(() => {
    if (!previewBase.value) {
        return null;
    }
    if (!hasAnyPushOption(pushOptions.value)) {
        return applyPushOptionsToPreview(previewBase.value, {
            info: false,
            images: false,
            quantities: false,
            price: false,
            publish_status: false,
            sales_channels: false,
        });
    }
    return applyPushOptionsToPreview(previewBase.value, pushOptions.value);
});

const scopeWarnings = computed(() => {
    const warnings: string[] = [];
    const data = previewBase.value;
    if (!data) {
        return warnings;
    }
    if (!data.write_products_scope_ok) {
        warnings.push('Missing write_products OAuth scope.');
    }
    if (pushOptions.value.quantities && !data.write_inventory_scope_ok) {
        warnings.push('Missing write_inventory OAuth scope for quantity push.');
    }
    if (pushOptions.value.sales_channels && !data.write_publications_scope_ok) {
        warnings.push('Missing write_publications OAuth scope for sales channel publish.');
    }
    if (pushOptions.value.images && !data.images_enabled) {
        warnings.push(
            'Cloudflare tunnel is off — it will be started automatically for image push and restored afterward.',
        );
    }
    if (pushOptions.value.quantities && data.location_gid === '') {
        warnings.push('No Shopify inventory location configured.');
    }
    return warnings;
});

watch(
    () => props.open,
    (next) => {
        if (!next) {
            previewBase.value = null;
            previewError.value = null;
            return;
        }
        pushOptions.value = defaultPushOptions();
        void loadPreviewBase();
    },
);

async function loadPreviewBase(): Promise<void> {
    if (props.selectedIds.length === 0) {
        previewBase.value = null;
        previewError.value = 'No products selected.';
        return;
    }

    previewLoading.value = true;
    previewError.value = null;
    try {
        const res = await api.post<{ ok: boolean; data: BulkPushShopifyPreview }>(
            '/api/v1/products/shopify-push/preview',
            {
                ids: props.selectedIds,
                push_options: {
                    info: true,
                    images: true,
                    quantities: true,
                    price: true,
                    publish_status: true,
                    sales_channels: true,
                },
            },
            { validateStatus: () => true },
        );
        if (res.status !== 200 || !res.data?.data) {
            throw new Error(`Preview failed (HTTP ${res.status}).`);
        }

        const data = res.data.data;
        previewBase.value = {
            location_gid: data.location_gid,
            location_name: data.location_name,
            write_products_scope_ok: data.write_products_scope_ok,
            write_inventory_scope_ok: data.write_inventory_scope_ok,
            write_publications_scope_ok: data.write_publications_scope_ok,
            images_enabled: data.images_enabled,
            tunnel_url: data.tunnel_url,
            products: data.products,
        };
    } catch (e: unknown) {
        previewBase.value = null;
        previewError.value = e instanceof Error ? e.message : 'Failed to load preview.';
    } finally {
        previewLoading.value = false;
    }
}

function skipLabel(reason: BulkPushShopifyPreviewRow['skip_reason']): string {
    switch (reason) {
        case 'missing_sku':
            return 'Missing SKU';
        case 'missing_selling_price':
            return 'Missing selling price';
        case 'missing_shopify_mirror':
            return 'Not in Shopify mirror';
        case 'create_requires_info':
            return 'Create requires Info';
        case 'create_requires_price':
            return 'Create requires Price';
        case 'missing_inventory_location':
            return 'No inventory location';
        case 'no_fields_selected':
            return 'No fields selected';
        default:
            return 'Skipped';
    }
}

function onConfirm(): void {
    if (!hasAnyPushOption(pushOptions.value)) {
        previewError.value = 'Select at least one field to push.';
        return;
    }
    if (!preview.value || preview.value.push_count === 0) {
        previewError.value = 'No eligible products to push.';
        return;
    }
    emit('confirm', { pushOptions: { ...pushOptions.value }, preview: preview.value });
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
            <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">Push selected to Shopify</div>
                    <div class="mt-1 text-sm text-slate-600">
                        Push
                        <span class="font-semibold text-slate-900">{{ selectedCount }}</span>
                        selected product(s). Choose which fields to sync.
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                            Fields to push
                        </div>

                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="pushOptions.info"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy || previewLoading"
                            />
                            Info (title, description, type, tags, SKU, barcode)
                        </label>

                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="pushOptions.images"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy || previewLoading"
                            />
                            Images (requires Cloudflare tunnel)
                        </label>

                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="pushOptions.quantities"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy || previewLoading"
                            />
                            Quantities (sellable = available − hold)
                        </label>

                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="pushOptions.price"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy || previewLoading"
                            />
                            Price
                        </label>

                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="pushOptions.publish_status"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy || previewLoading"
                            />
                            Publish status (ACTIVE / DRAFT / ARCHIVED)
                        </label>

                        <label class="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                v-model="pushOptions.sales_channels"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :disabled="busy || previewLoading"
                            />
                            Sales channels (all publications when published)
                        </label>
                    </div>

                    <div v-if="previewLoading" class="mt-4 text-sm text-slate-600">Loading preview…</div>

                    <p v-if="previewError" class="mt-4 text-sm text-rose-700">{{ previewError }}</p>

                    <div
                        v-if="scopeWarnings.length > 0"
                        class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
                    >
                        <div v-for="(warning, idx) in scopeWarnings" :key="idx">{{ warning }}</div>
                    </div>

                    <div v-if="preview" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                        <div class="font-semibold text-slate-900">
                            {{ preview.push_count }} eligible
                            <span class="font-normal text-slate-600">
                                ({{ preview.create_count }} create, {{ preview.update_count }} update,
                                {{ preview.skip_count }} skipped)
                            </span>
                        </div>
                        <div v-if="preview.location_name" class="mt-1 text-xs text-slate-600">
                            Inventory location: {{ preview.location_name }}
                        </div>
                    </div>

                    <div
                        v-if="preview && preview.skip_count > 0"
                        class="mt-3 max-h-40 overflow-y-auto rounded-md border border-slate-200"
                    >
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-100 text-slate-600">
                                <tr>
                                    <th class="px-2 py-1.5 font-medium">SKU</th>
                                    <th class="px-2 py-1.5 font-medium">Action</th>
                                    <th class="px-2 py-1.5 font-medium">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in preview.products.filter((r) => !r.push_eligible)"
                                    :key="row.product_uuid"
                                    class="border-t border-slate-100"
                                >
                                    <td class="px-2 py-1.5">{{ row.sku || '—' }}</td>
                                    <td class="px-2 py-1.5">{{ row.push_action }}</td>
                                    <td class="px-2 py-1.5">{{ skipLabel(row.skip_reason) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy || previewLoading"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy || previewLoading || !preview || preview.push_count === 0"
                        @click="onConfirm"
                    >
                        {{ busy ? 'Queuing…' : 'Push to Shopify' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

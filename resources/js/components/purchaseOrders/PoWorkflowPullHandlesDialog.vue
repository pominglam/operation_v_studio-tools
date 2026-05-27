<script setup lang="ts">
export type PoPullHandlesPreviewRow = {
    product_uuid: string;
    sku: string;
    description: string;
    handle: string | null;
    mirror_handle: string | null;
};

export type PoPullHandlesPreview = {
    products: PoPullHandlesPreviewRow[];
    pull_count: number;
    already_has_handle_count: number;
    product_uuids: string[];
};

export type PoPullHandlesSummary = {
    sync_status: string;
    updated: number;
    skipped_already_has_handle: number;
    missing_in_shopify: string[];
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    preview: PoPullHandlesPreview | null;
    pullSummary: PoPullHandlesSummary | null;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm'): void;
}>();

function formatHandle(value: string | null | undefined): string {
    if (value == null || value.trim() === '') return '—';
    return value;
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
                class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-lg bg-white shadow-xl"
            >
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">Pull handles from Shopify</div>
                    <div v-if="preview" class="mt-1 text-xs text-slate-600">
                        Products on this PO ·
                        <span class="font-semibold text-slate-900">{{ preview.pull_count }}</span>
                        missing a local handle
                        <span v-if="preview.already_has_handle_count > 0">
                            ·
                            <span class="font-semibold text-slate-900">{{
                                preview.already_has_handle_count
                            }}</span>
                            already stored
                        </span>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="error" class="mb-3 text-sm text-rose-700">{{ error }}</p>

                    <div
                        v-if="pullSummary"
                        class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                    >
                        Updated {{ pullSummary.updated }} product handle(s) from Shopify.
                        <span v-if="pullSummary.skipped_already_has_handle > 0">
                            {{ pullSummary.skipped_already_has_handle }} already had handles.
                        </span>
                    </div>

                    <template v-if="preview">
                        <p
                            v-if="preview.pull_count === 0"
                            class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                        >
                            All products on this PO already have handles stored locally. There is
                            no need to pull handles from Shopify — for example, if you already used
                            <span class="font-medium">Push to Shopify</span> on the export step.
                        </p>

                        <template v-else>
                            <p class="mb-3 text-xs text-slate-600">
                                Confirming will sync products from Shopify (read-only), then copy
                                handles into the ERP for the products listed below. Use this when
                                products already exist in Shopify but handles were not saved locally.
                            </p>

                            <p
                                v-if="preview.products.some((row) => !row.mirror_handle)"
                                class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                            >
                                Some SKUs are not in the current Shopify mirror yet. A fresh sync
                                runs on confirm; any SKU still missing in Shopify afterward will be
                                reported.
                            </p>

                            <section>
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">
                                    Products without local handle ({{ preview.products.length }})
                                </div>
                                <table class="mt-2 w-full text-left text-xs">
                                    <thead class="text-slate-500">
                                        <tr>
                                            <th class="py-1 pr-2">SKU</th>
                                            <th class="py-1 pr-2">Product</th>
                                            <th class="py-1 pr-2">Local handle</th>
                                            <th class="py-1">Mirror handle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in preview.products"
                                            :key="row.product_uuid"
                                            class="border-t border-slate-100 text-slate-800"
                                        >
                                            <td class="py-1.5 pr-2 font-medium">{{ row.sku }}</td>
                                            <td
                                                class="max-w-[16rem] py-1.5 pr-2 text-slate-700"
                                                :title="row.description"
                                            >
                                                {{ row.description || '—' }}
                                            </td>
                                            <td class="py-1.5 pr-2 text-slate-500">
                                                {{ formatHandle(row.handle) }}
                                            </td>
                                            <td class="py-1.5">
                                                <span
                                                    v-if="row.mirror_handle"
                                                    class="text-emerald-800"
                                                >
                                                    {{ row.mirror_handle }}
                                                </span>
                                                <span v-else class="text-amber-800">
                                                    Not in mirror yet
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </section>
                        </template>

                        <section
                            v-if="pullSummary && pullSummary.missing_in_shopify.length"
                            class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3"
                        >
                            <div class="text-xs font-semibold uppercase text-rose-800">
                                Not found in Shopify
                            </div>
                            <ul class="mt-2 space-y-1 text-xs text-rose-900">
                                <li v-for="sku in pullSummary.missing_in_shopify" :key="sku">
                                    {{ sku }}
                                </li>
                            </ul>
                        </section>
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
                        {{ pullSummary ? 'Close' : 'Cancel' }}
                    </button>
                    <button
                        v-if="!pullSummary"
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busy || !preview || preview.pull_count === 0"
                        @click="emit('confirm')"
                    >
                        {{ busy ? 'Pulling…' : 'Pull handles' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

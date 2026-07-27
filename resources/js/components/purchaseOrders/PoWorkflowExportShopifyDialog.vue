<script setup lang="ts">
export type PoExportShopifyPreviewRow = {
    product_uuid: string;
    sku: string;
    description: string;
    handle: string | null;
    export_eligible: boolean;
    skip_reason: 'missing_selling_price' | null;
};

export type PoExportShopifyPreview = {
    export_type: string;
    export_type_label: string;
    write_scope_ok: boolean;
    images_enabled: boolean;
    tunnel_url: string | null;
    products: PoExportShopifyPreviewRow[];
    export_count: number;
    product_uuids: string[];
};

export type PoExportShopifyPushSummary = {
    created: number;
    failed: number;
    skipped: number;
    images_enabled: boolean;
    results: Array<{ sku: string; handle: string; shopify_gid: string }>;
    errors: Array<{ sku: string; message: string }>;
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    preview: PoExportShopifyPreview | null;
    pushSummary: PoExportShopifyPushSummary | null;
    error: string | null;
    progressPercent?: number;
    phaseLabel?: string;
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
                    <div class="text-sm font-semibold text-slate-900">Export to Shopify</div>
                    <div v-if="preview" class="mt-1 text-xs text-slate-600">
                        Export type:
                        <span class="font-semibold text-slate-900">{{
                            preview.export_type_label
                        }}</span>
                        ·
                        <span class="font-semibold text-slate-900">{{ preview.export_count }}</span>
                        product(s) on this PO without a handle will be created in Shopify.
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="error" class="mb-3 text-sm text-rose-700">{{ error }}</p>

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
                        Created {{ pushSummary.created }} product(s) in Shopify.
                        <span v-if="pushSummary.failed > 0">
                            {{ pushSummary.failed }} failed.
                        </span>
                    </div>

                    <template v-if="preview">
                        <p
                            v-if="!preview.write_scope_ok"
                            class="mb-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800"
                        >
                            Shopify OAuth is missing <span class="font-semibold">write_products</span>
                            scope. Add it to <span class="font-semibold">SHOPIFY_OAUTH_SCOPES</span>,
                            then re-install the Shopify app before pushing.
                        </p>

                        <p
                            v-else-if="!preview.images_enabled"
                            class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                        >
                            Image tunnel is off — it will be started automatically for this export
                            and restored afterward.
                        </p>

                        <p v-else class="mb-3 text-xs text-slate-600">
                            These products on this PO have no handle stored yet. Pushing creates
                            them in Shopify (images + description, no inventory). Handles are saved
                            locally when the push succeeds.
                        </p>

                        <section v-if="preview.products.length">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">
                                Products without handle ({{ preview.products.length }})
                            </div>
                            <table class="mt-2 w-full text-left text-xs">
                                <thead class="text-slate-500">
                                    <tr>
                                        <th class="py-1 pr-2">SKU</th>
                                        <th class="py-1 pr-2">Product</th>
                                        <th class="py-1 pr-2">Handle</th>
                                        <th class="py-1">Status</th>
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
                                                v-if="row.export_eligible"
                                                class="text-emerald-800"
                                            >
                                                Ready to push
                                            </span>
                                            <span v-else class="text-amber-800">
                                                Missing selling price
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section
                            v-if="pushSummary && pushSummary.errors.length"
                            class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3"
                        >
                            <div class="text-xs font-semibold uppercase text-rose-800">Errors</div>
                            <ul class="mt-2 space-y-1 text-xs text-rose-900">
                                <li v-for="item in pushSummary.errors" :key="item.sku">
                                    {{ item.sku }}: {{ item.message }}
                                </li>
                            </ul>
                        </section>

                        <p v-if="preview.products.length === 0" class="text-sm text-slate-600">
                            All products on this PO already have handles stored.
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
                        {{ pushSummary ? 'Close' : 'Cancel' }}
                    </button>
                    <button
                        v-if="!pushSummary"
                        type="button"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="
                            busy ||
                            !preview ||
                            preview.export_count === 0 ||
                            !preview.write_scope_ok
                        "
                        @click="emit('confirm')"
                    >
                        {{ busy ? (phaseLabel || 'Pushing…') : 'Push to Shopify' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

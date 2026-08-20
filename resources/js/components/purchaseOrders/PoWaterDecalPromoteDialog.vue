<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { formatMoney2OrEmpty } from '../../lib/money';

export type WaterDecalMergeTarget = {
    product_id: number;
    sku: string;
    description: string | null;
    handle: string | null;
    selling_price: string | null;
};

export type WaterDecalPromoteRow = {
    item_id: number;
    intention: string;
    intention_label: string;
    current_sku: string;
    current_description: string;
    current_main_type: string | null;
    proposed_sku: string;
    proposed_description: string;
    proposed_vendor: string;
    proposed_type: string;
    merge_target: WaterDecalMergeTarget | null;
    warning: string | null;
    confirm_merge: boolean;
};

const props = defineProps<{
    open: boolean;
    busy: boolean;
    previewBusy: boolean;
    rows: WaterDecalPromoteRow[];
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'confirm', rows: WaterDecalPromoteRow[]): void;
    (e: 'refresh', rows: WaterDecalPromoteRow[]): void;
}>();

const localRows = ref<WaterDecalPromoteRow[]>([]);
const localError = ref<string | null>(null);

watch(
    () => props.rows,
    (next) => {
        localRows.value = next.map((row) => ({ ...row, confirm_merge: row.confirm_merge ?? false }));
    },
    { immediate: true, deep: true },
);

watch(
    () => props.open,
    (next) => {
        if (next) {
            localError.value = null;
        }
    },
);

const mergeRowsMissingConfirm = computed<number>(() =>
    localRows.value.filter((row) => row.intention === 'merge' && !row.confirm_merge).length,
);

function intentionClass(intention: string): string {
    if (intention === 'merge') {
        return 'border-amber-200 bg-amber-50 text-amber-900';
    }
    if (intention === 'blocked') {
        return 'border-rose-200 bg-rose-50 text-rose-900';
    }
    if (intention === 'noop') {
        return 'border-slate-200 bg-slate-100 text-slate-700';
    }

    return 'border-sky-200 bg-sky-50 text-sky-900';
}

function onSkuBlur(): void {
    emit('refresh', localRows.value);
}

function onConfirm(): void {
    localError.value = null;

    if (localRows.value.length === 0) {
        localError.value = 'Nothing to apply.';
        return;
    }

    if (mergeRowsMissingConfirm.value > 0) {
        localError.value = `Confirm merge for ${mergeRowsMissingConfirm.value} row(s).`;
        return;
    }

    emit('confirm', localRows.value);
}

const displayError = computed<string | null>(() => props.error ?? localError.value);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Promote to water decals preview"
            @click.self="emit('cancel')"
        >
            <div class="flex max-h-[90vh] w-full max-w-6xl flex-col rounded-lg bg-white shadow-xl">
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="text-sm font-semibold text-slate-900">
                        Promote to water decals — preview
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        Review each row. The system labels its intention; edit SKU, title, vendor, or
                        grade before applying.
                    </p>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="displayError" class="mb-3 text-sm text-rose-700">{{ displayError }}</p>
                    <p v-if="previewBusy" class="mb-3 text-sm text-slate-600">Refreshing preview…</p>

                    <div class="space-y-3">
                        <div
                            v-for="row in localRows"
                            :key="row.item_id"
                            class="rounded-md border border-slate-200 p-3"
                            data-testid="water-decal-promote-row"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <div class="text-xs text-slate-500">PO line SKU</div>
                                    <div class="font-mono text-sm font-semibold text-slate-900">
                                        {{ row.current_sku }}
                                    </div>
                                    <div class="mt-1 text-xs text-slate-600">
                                        {{ row.current_description }}
                                    </div>
                                </div>
                                <div
                                    class="rounded-full border px-2 py-1 text-xs font-semibold"
                                    :class="intentionClass(row.intention)"
                                >
                                    {{ row.intention_label }}
                                </div>
                            </div>

                            <p v-if="row.warning" class="mt-2 text-xs text-amber-800">{{ row.warning }}</p>

                            <div
                                v-if="row.merge_target"
                                class="mt-2 rounded-md border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-950"
                            >
                                Merge target:
                                <span class="font-mono font-semibold">{{ row.merge_target.sku }}</span>
                                <span v-if="row.merge_target.handle">
                                    · handle {{ row.merge_target.handle }}</span
                                >
                                <span v-if="row.merge_target.selling_price">
                                    · {{ formatMoney2OrEmpty(row.merge_target.selling_price) }}</span
                                >
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs">
                                    <span class="mb-1 block font-medium text-slate-700">Proposed SKU</span>
                                    <input
                                        v-model="row.proposed_sku"
                                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 font-mono text-sm"
                                        type="text"
                                        @blur="onSkuBlur"
                                    />
                                </label>
                                <label class="block text-xs">
                                    <span class="mb-1 block font-medium text-slate-700">Grade (type)</span>
                                    <input
                                        v-model="row.proposed_type"
                                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                        type="text"
                                    />
                                </label>
                                <label class="block text-xs sm:col-span-2">
                                    <span class="mb-1 block font-medium text-slate-700">Proposed title</span>
                                    <input
                                        v-model="row.proposed_description"
                                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                        type="text"
                                    />
                                </label>
                                <label class="block text-xs sm:col-span-2">
                                    <span class="mb-1 block font-medium text-slate-700">Vendor</span>
                                    <input
                                        v-model="row.proposed_vendor"
                                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                        type="text"
                                    />
                                </label>
                            </div>

                            <label
                                v-if="row.intention === 'merge'"
                                class="mt-3 flex items-center gap-2 text-xs text-slate-700"
                            >
                                <input v-model="row.confirm_merge" type="checkbox" />
                                Confirm merge into existing catalog product
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        type="button"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        type="button"
                        data-testid="water-decal-promote-apply"
                        :disabled="busy || previewBusy || localRows.length === 0"
                        @click="onConfirm"
                    >
                        {{ busy ? 'Applying…' : 'Apply' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

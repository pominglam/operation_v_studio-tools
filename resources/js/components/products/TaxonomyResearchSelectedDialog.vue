<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { taxonomyResearchFields, type TaxonomyResearchField } from '../../types/productTaxonomy';

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    confirm: [fields: TaxonomyResearchField[]];
    cancel: [];
}>();

const selectedFields = reactive<Record<TaxonomyResearchField, boolean>>(emptyFieldSelection());
const localError = ref<string | null>(null);

function emptyFieldSelection(): Record<TaxonomyResearchField, boolean> {
    const selection = {} as Record<TaxonomyResearchField, boolean>;
    for (const field of taxonomyResearchFields) {
        selection[field.key] = true;
    }

    return selection;
}

function reset(): void {
    for (const field of taxonomyResearchFields) {
        selectedFields[field.key] = true;
    }
    localError.value = null;
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            reset();
        }
    },
);

const checkedCount = computed(
    () => taxonomyResearchFields.filter((field) => selectedFields[field.key]).length,
);

function selectAll(): void {
    for (const field of taxonomyResearchFields) {
        selectedFields[field.key] = true;
    }
    localError.value = null;
}

function clearAll(): void {
    for (const field of taxonomyResearchFields) {
        selectedFields[field.key] = false;
    }
}

function selectSeriesOnly(): void {
    clearAll();
    selectedFields.series = true;
    localError.value = null;
}

function submit(): void {
    const fields = taxonomyResearchFields
        .filter((field) => selectedFields[field.key])
        .map((field) => field.key);
    if (fields.length === 0) {
        localError.value = 'Select at least one field to research.';
        return;
    }
    emit('confirm', fields);
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
        role="dialog"
        aria-modal="true"
        @click.self="emit('cancel')"
    >
        <form
            class="w-full max-w-2xl space-y-4 rounded-lg bg-white p-5 shadow-xl"
            @submit.prevent="submit"
        >
            <div>
                <h2 class="text-sm font-semibold text-slate-900">
                    Research {{ selectedCount }} selected row(s)
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    Re-runs taxonomy derivation for the checked fields only. Each row becomes
                    proposed with fresh evidence for those fields — ERP is not updated until you
                    approve. Unchecked fields keep their current proposed values.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <button
                    type="button"
                    class="rounded border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-50"
                    :disabled="busy"
                    @click="selectAll"
                >
                    Select all
                </button>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-2 py-1 text-slate-700 hover:bg-slate-50"
                    :disabled="busy"
                    @click="clearAll"
                >
                    Clear all
                </button>
                <button
                    type="button"
                    data-testid="taxonomy-research-series-only"
                    class="rounded border border-sky-300 px-2 py-1 text-sky-800 hover:bg-sky-50"
                    :disabled="busy"
                    @click="selectSeriesOnly"
                >
                    Series only
                </button>
                <span class="text-slate-500">{{ checkedCount }} field(s) selected</span>
            </div>

            <p v-if="localError" class="text-sm text-red-700">{{ localError }}</p>

            <div class="grid gap-2 sm:grid-cols-2">
                <label
                    v-for="field in taxonomyResearchFields"
                    :key="field.key"
                    class="flex items-center gap-2 text-sm text-slate-700"
                >
                    <input
                        v-model="selectedFields[field.key]"
                        type="checkbox"
                        :disabled="busy"
                        :data-testid="`taxonomy-research-field-${field.key}`"
                    />
                    {{ field.label }}
                </label>
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    :disabled="busy"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    data-testid="taxonomy-research-confirm"
                    type="button"
                    class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="busy || selectedCount === 0"
                    @click="submit"
                >
                    Research selected
                </button>
            </div>
        </form>
    </div>
</template>

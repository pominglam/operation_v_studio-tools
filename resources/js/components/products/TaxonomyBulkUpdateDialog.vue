<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import CreatableSelect from '../ui/CreatableSelect.vue';
import {
    taxonomyFields,
    type TaxonomyField,
    type TaxonomyValues,
} from '../../types/productTaxonomy';

export type TaxonomyOptionKey = TaxonomyField;

const props = defineProps<{
    open: boolean;
    selectedCount: number;
    busy: boolean;
    options: Record<TaxonomyOptionKey, string[]> & { workshop_shelf?: string[] };
}>();

const emit = defineEmits<{
    confirm: [values: Partial<TaxonomyValues>, notes: string | null];
    cancel: [];
}>();

type FieldState = { apply: boolean; value: string };

const departmentDefaults = ['model kits', 'tools', 'supplies', 'misc', 'paints'];

const fields = reactive<Record<TaxonomyField, FieldState>>(emptyFields());
const workshopShelf = reactive<FieldState>({ apply: false, value: '' });
const notes = ref('');
const localError = ref<string | null>(null);

function emptyFields(): Record<TaxonomyField, FieldState> {
    return {
        department: { apply: false, value: '' },
        manufacturer: { apply: false, value: '' },
        franchise: { apply: false, value: '' },
        product_line: { apply: false, value: '' },
        subline: { apply: false, value: '' },
        grade: { apply: false, value: '' },
        series: { apply: false, value: '' },
        scale: { apply: false, value: '' },
    };
}

function reset(): void {
    Object.assign(fields, emptyFields());
    workshopShelf.apply = false;
    workshopShelf.value = '';
    notes.value = '';
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

const fieldOptions = computed(() => ({
    department: Array.from(new Set([...departmentDefaults, ...props.options.department])).sort(
        (a, b) => a.localeCompare(b),
    ),
    manufacturer: props.options.manufacturer,
    franchise: props.options.franchise,
    product_line: props.options.product_line,
    subline: props.options.subline,
    grade: props.options.grade,
    series: props.options.series,
    scale: props.options.scale,
}));

const workshopShelfOptions = computed(() => props.options.workshop_shelf ?? []);

function submit(): void {
    const values: Partial<TaxonomyValues> = {};
    for (const field of taxonomyFields) {
        if (!fields[field.key].apply) {
            continue;
        }
        const next = fields[field.key].value.trim();
        values[field.key] = next === '' ? null : next;
    }
    if (workshopShelf.apply) {
        const next = workshopShelf.value.trim();
        values.workshop_shelf = next === '' ? null : next;
    }
    if (Object.keys(values).length === 0) {
        localError.value = 'Select at least one field to update.';
        return;
    }
    emit('confirm', values, notes.value.trim() || null);
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
                    Bulk update {{ selectedCount }} selected
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    Checked fields replace the proposed values and are saved as operator overrides.
                    Pick from the list or choose “Add new…” to type a value. Test SKUs are skipped.
                </p>
            </div>
            <p v-if="localError" class="text-sm text-red-700">{{ localError }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label
                    v-for="field in taxonomyFields"
                    :key="field.key"
                    class="flex items-start gap-2 text-sm text-slate-700"
                >
                    <input
                        v-model="fields[field.key].apply"
                        class="mt-2"
                        type="checkbox"
                        :data-testid="`taxonomy-bulk-apply-${field.key}`"
                    />
                    <span class="min-w-0 flex-1">
                        {{ field.label }}
                        <CreatableSelect
                            v-model="fields[field.key].value"
                            class="mt-1"
                            :options="fieldOptions[field.key]"
                            :disabled="!fields[field.key].apply || busy"
                            :placeholder="`Select ${field.label.toLowerCase()}…`"
                            :select-test-id="`taxonomy-bulk-value-${field.key}`"
                            :input-test-id="`taxonomy-bulk-value-custom-${field.key}`"
                        />
                    </span>
                </label>
            </div>
            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input v-model="workshopShelf.apply" class="mt-2" type="checkbox" />
                <span class="min-w-0 flex-1">
                    T&amp;S shelf
                    <CreatableSelect
                        v-model="workshopShelf.value"
                        class="mt-1"
                        :options="workshopShelfOptions"
                        :disabled="!workshopShelf.apply || busy"
                        placeholder="Select T&amp;S shelf…"
                    />
                </span>
            </label>
            <label class="block text-sm text-slate-700">
                Notes
                <textarea
                    v-model="notes"
                    class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                    rows="2"
                />
            </label>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    data-testid="taxonomy-bulk-update-confirm"
                    type="button"
                    class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :disabled="busy || selectedCount === 0"
                    @click="submit"
                >
                    Apply override
                </button>
            </div>
        </form>
    </div>
</template>

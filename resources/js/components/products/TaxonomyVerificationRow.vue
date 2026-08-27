<script setup lang="ts">
import { ref } from 'vue';
import {
    formatAccessoryKind,
    formatWorkshopFacets,
    normalizeTaxonomyValues,
    taxonomyFields,
    workshopTaxonomyFields,
    type TaxonomyField,
    type TaxonomyValues,
    type TaxonomyVerification,
} from '../../types/productTaxonomy';

const props = defineProps<{
    item: TaxonomyVerification;
    busy: boolean;
    selected: boolean;
}>();

const emit = defineEmits<{
    approve: [item: TaxonomyVerification, values: TaxonomyValues | null, notes: string | null];
    toggle: [id: string, selected: boolean];
}>();

const expanded = ref(false);
const editing = ref(false);
const editValues = ref<TaxonomyValues>(normalizeTaxonomyValues(props.item.proposed_values));
const editFacetsJson = ref('');
const editNotes = ref(props.item.operator_notes ?? '');

function displayValue(value: string | null): string {
    return value && value.trim() !== '' ? value : '—';
}

function appliedValues(item: TaxonomyVerification): TaxonomyValues {
    return normalizeTaxonomyValues(item.product);
}

function currentValues(item: TaxonomyVerification): TaxonomyValues {
    return item.status === 'proposed'
        ? normalizeTaxonomyValues(item.proposed_values)
        : appliedValues(item);
}

function valuesDiffer(field: TaxonomyField): boolean {
    return props.item.previous_values[field] !== currentValues(props.item)[field];
}

function workshopShelfDiffers(): boolean {
    return props.item.previous_values.workshop_shelf !== currentValues(props.item).workshop_shelf;
}

function workshopFacetsDiffer(): boolean {
    return (
        JSON.stringify(props.item.previous_values.workshop_facets ?? {}) !==
        JSON.stringify(currentValues(props.item).workshop_facets ?? {})
    );
}

function accessoryKindDiffers(): boolean {
    return props.item.previous_values.accessory_kind !== currentValues(props.item).accessory_kind;
}

function cellValue(field: TaxonomyField): string | null {
    return currentValues(props.item)[field];
}

function startOverride(): void {
    editValues.value = { ...currentValues(props.item) };
    editFacetsJson.value = JSON.stringify(editValues.value.workshop_facets ?? {}, null, 2);
    editNotes.value = props.item.operator_notes ?? '';
    editing.value = true;
    expanded.value = true;
}

function cancelOverride(): void {
    editing.value = false;
}

function approveUnchanged(): void {
    emit('approve', props.item, null, null);
}

function saveOverride(): void {
    const next = { ...editValues.value };
    try {
        const parsed = JSON.parse(editFacetsJson.value || '{}');
        next.workshop_facets =
            parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        next.workshop_facets = {};
    }
    emit('approve', props.item, next, editNotes.value.trim() || null);
}
</script>

<template>
    <tr class="border-t border-slate-200 align-top hover:bg-slate-50/80">
        <td class="sticky left-0 z-10 bg-white px-3 py-2">
            <input
                data-testid="taxonomy-select-row"
                type="checkbox"
                class="h-4 w-4 rounded border-slate-300"
                :checked="selected"
                :disabled="item.status !== 'proposed'"
                @change="emit('toggle', item.id, ($event.target as HTMLInputElement).checked)"
            />
        </td>
        <td
            class="sticky left-10 z-10 bg-white px-3 py-2 font-mono text-xs font-semibold text-slate-900"
        >
            {{ item.product.sku }}
        </td>
        <td class="min-w-56 px-3 py-2 text-sm text-slate-800">
            <p>{{ item.product.description }}</p>
            <p v-if="item.product.archived" class="mt-1 text-xs text-amber-700">Archived</p>
        </td>
        <td class="px-3 py-2">
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                {{ item.status }}
            </span>
        </td>
        <td class="px-3 py-2 text-sm tabular-nums text-slate-800">
            {{ item.overall_confidence }}%
        </td>
        <td
            v-for="field in taxonomyFields"
            :key="`${item.id}-${field.key}`"
            class="min-w-28 px-3 py-2 text-sm"
        >
            <span :class="{ 'font-semibold text-sky-800': valuesDiffer(field.key) }">
                {{ displayValue(cellValue(field.key)) }}
            </span>
            <span
                v-if="valuesDiffer(field.key)"
                data-testid="taxonomy-current-value"
                class="mt-0.5 block text-xs text-slate-500"
            >
                was {{ displayValue(item.previous_values[field.key]) }}
            </span>
        </td>
        <td class="min-w-32 px-3 py-2 text-sm">
            <span :class="{ 'font-semibold text-sky-800': workshopShelfDiffers() }">
                {{ displayValue(currentValues(item).workshop_shelf) }}
            </span>
        </td>
        <td class="min-w-48 px-3 py-2 text-xs text-slate-700">
            <span :class="{ 'font-semibold text-sky-800': workshopFacetsDiffer() }">
                {{ formatWorkshopFacets(currentValues(item).workshop_facets) }}
            </span>
        </td>
        <td class="min-w-32 px-3 py-2 text-sm">
            <span :class="{ 'font-semibold text-sky-800': accessoryKindDiffers() }">
                {{ formatAccessoryKind(currentValues(item).accessory_kind) }}
            </span>
        </td>
        <td class="sticky right-0 z-10 bg-white px-3 py-2">
            <div class="flex flex-col items-start gap-1">
                <template v-if="item.status === 'proposed'">
                    <button
                        data-testid="taxonomy-approve"
                        type="button"
                        class="text-sm font-semibold text-emerald-700 hover:underline disabled:opacity-50"
                        :disabled="busy"
                        @click="approveUnchanged"
                    >
                        Approve
                    </button>
                    <button
                        type="button"
                        class="text-sm font-semibold text-slate-700 hover:underline"
                        @click="startOverride"
                    >
                        Edit
                    </button>
                </template>
                <button
                    data-testid="taxonomy-evidence-toggle"
                    type="button"
                    class="text-sm font-semibold text-sky-700 hover:underline"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? 'Hide evidence' : 'Show evidence' }}
                </button>
            </div>
        </td>
    </tr>
    <tr v-if="expanded" class="border-t border-slate-100 bg-slate-50">
        <td :colspan="16" class="px-4 py-4">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="field in [
                        ...taxonomyFields,
                        ...workshopTaxonomyFields,
                        { key: 'accessory_kind', label: 'Accessory kind' },
                    ]"
                    :key="`evidence-${field.key}`"
                    class="text-sm"
                >
                    <p class="font-semibold text-slate-800">{{ field.label }}</p>
                    <template v-if="item.evidence[field.key]">
                        <a
                            v-if="item.evidence[field.key]?.source_url"
                            :href="item.evidence[field.key]?.source_url ?? undefined"
                            target="_blank"
                            rel="noreferrer"
                            class="text-sky-700 hover:underline"
                        >
                            {{ item.evidence[field.key]?.source_label }}
                        </a>
                        <span v-else class="text-slate-600">{{
                            item.evidence[field.key]?.source_label
                        }}</span>
                        <span class="ml-2 text-xs text-slate-500">
                            {{ item.evidence[field.key]?.confidence }}%
                        </span>
                    </template>
                    <span v-else class="text-slate-500">No evidence recorded</span>
                </div>
            </div>

            <form
                v-if="editing"
                class="mt-4 space-y-3 border-t border-slate-200 pt-4"
                @submit.prevent="saveOverride"
            >
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label
                        v-for="field in taxonomyFields"
                        :key="`edit-${field.key}`"
                        class="text-xs font-medium text-slate-700"
                    >
                        {{ field.label }}
                        <input
                            v-model="editValues[field.key]"
                            class="mt-1 w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                            type="text"
                        />
                    </label>
                    <label class="text-xs font-medium text-slate-700">
                        T&amp;S shelf
                        <input
                            v-model="editValues.workshop_shelf"
                            class="mt-1 w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                            type="text"
                        />
                    </label>
                    <label class="text-xs font-medium text-slate-700">
                        Accessory kind
                        <input
                            v-model="editValues.accessory_kind"
                            class="mt-1 w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                            type="text"
                            placeholder="display_stand, option_parts, …"
                        />
                    </label>
                </div>
                <label class="block text-xs font-medium text-slate-700">
                    Workshop facets (JSON)
                    <textarea
                        v-model="editFacetsJson"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 font-mono text-xs"
                        rows="4"
                    />
                </label>
                <label class="block text-xs font-medium text-slate-700">
                    Verification notes
                    <textarea
                        v-model="editNotes"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
                        rows="2"
                    />
                </label>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white"
                    >
                        Save override
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        @click="cancelOverride"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </td>
    </tr>
</template>

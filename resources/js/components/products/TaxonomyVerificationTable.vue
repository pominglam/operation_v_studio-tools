<script setup lang="ts">
import { computed } from 'vue';
import {
    taxonomyFields,
    workshopTaxonomyFields,
    type TaxonomyValues,
    type TaxonomyVerification,
} from '../../types/productTaxonomy';
import TaxonomyVerificationRow from './TaxonomyVerificationRow.vue';

const props = defineProps<{
    items: TaxonomyVerification[];
    busyId: string | null;
    selectedIds: string[];
}>();

const emit = defineEmits<{
    approve: [item: TaxonomyVerification, values: TaxonomyValues | null, notes: string | null];
    'update:selectedIds': [ids: string[]];
}>();

const proposedIds = computed(() =>
    props.items.filter((item) => item.status === 'proposed').map((item) => item.id),
);

const allSelected = computed(
    () =>
        proposedIds.value.length > 0 &&
        proposedIds.value.every((id) => props.selectedIds.includes(id)),
);

function toggleRow(id: string, selected: boolean): void {
    const next = new Set(props.selectedIds);
    if (selected) {
        next.add(id);
    } else {
        next.delete(id);
    }
    emit('update:selectedIds', [...next]);
}

function toggleAll(selected: boolean): void {
    emit('update:selectedIds', selected ? [...proposedIds.value] : []);
}
</script>

<template>
    <div class="overflow-x-auto">
        <table data-testid="taxonomy-review-table" class="min-w-full border-collapse text-left">
            <thead class="sticky top-0 z-20 bg-slate-50">
                <tr class="text-xs font-semibold tracking-wide text-slate-600">
                    <th class="sticky left-0 z-30 bg-slate-50 px-3 py-3">
                        <input
                            data-testid="taxonomy-select-all"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :checked="allSelected"
                            :disabled="proposedIds.length === 0"
                            @change="toggleAll(($event.target as HTMLInputElement).checked)"
                        />
                    </th>
                    <th class="sticky left-10 z-30 bg-slate-50 px-3 py-3">SKU</th>
                    <th class="px-3 py-3">Product</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3">Conf.</th>
                    <th v-for="field in taxonomyFields" :key="field.key" class="px-3 py-3">
                        {{ field.label }}
                    </th>
                    <th v-for="field in workshopTaxonomyFields" :key="field.key" class="px-3 py-3">
                        {{ field.label }}
                    </th>
                    <th class="px-3 py-3">Accessory kind</th>
                    <th class="sticky right-0 z-30 bg-slate-50 px-3 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <TaxonomyVerificationRow
                    v-for="item in items"
                    :key="item.id"
                    :item="item"
                    :busy="busyId === item.id"
                    :selected="selectedIds.includes(item.id)"
                    @approve="(row, values, notes) => emit('approve', row, values, notes)"
                    @toggle="toggleRow"
                />
            </tbody>
        </table>
    </div>
</template>

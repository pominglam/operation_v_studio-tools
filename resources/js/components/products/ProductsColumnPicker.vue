<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    PRODUCTS_TABLE_COLUMN_GROUPS,
    PRODUCTS_TABLE_COLUMN_OPTIONS,
    type ProductsTableColumnKey,
    toggleProductsTableColumn,
} from '../../lib/productsTableColumns';

const props = defineProps<{
    visibleColumns: ProductsTableColumnKey[];
}>();

const emit = defineEmits<{
    (e: 'update:visibleColumns', value: ProductsTableColumnKey[]): void;
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const hiddenCount = computed(
    () => PRODUCTS_TABLE_COLUMN_OPTIONS.length - props.visibleColumns.length,
);

function isChecked(key: ProductsTableColumnKey): boolean {
    return props.visibleColumns.includes(key);
}

function toggle(key: ProductsTableColumnKey): void {
    emit('update:visibleColumns', toggleProductsTableColumn(props.visibleColumns, key));
}

function onDocumentClick(event: MouseEvent): void {
    if (!open.value || !root.value) {
        return;
    }
    if (event.target instanceof Node && root.value.contains(event.target)) {
        return;
    }
    open.value = false;
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <div ref="root" class="relative" data-testid="products-column-picker">
        <button
            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 transition hover:bg-slate-50"
            type="button"
            data-testid="products-column-picker-toggle"
            :aria-expanded="open"
            aria-haspopup="true"
            @click="open = !open"
        >
            Columns{{ hiddenCount > 0 ? ` (${hiddenCount} hidden)` : '' }}
        </button>
        <div
            v-if="open"
            class="absolute right-0 z-40 mt-1 max-h-[min(28rem,70vh)] w-72 overflow-y-auto rounded-md border border-slate-200 bg-white p-3 shadow-lg"
            data-testid="products-column-picker-panel"
        >
            <p class="mb-2 text-xs text-slate-500">
                Product and checkbox stay visible. Save a view to keep this set.
            </p>
            <div
                v-for="group in PRODUCTS_TABLE_COLUMN_GROUPS"
                :key="group.id"
                class="mb-3 last:mb-0"
            >
                <div class="mb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
                    {{ group.label }}
                </div>
                <label
                    v-for="column in PRODUCTS_TABLE_COLUMN_OPTIONS.filter(
                        (option) => option.group === group.id,
                    )"
                    :key="column.key"
                    class="flex items-center gap-2 py-0.5 text-sm text-slate-800"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300"
                        :checked="isChecked(column.key)"
                        :data-testid="`products-column-${column.key}`"
                        @change="toggle(column.key)"
                    />
                    {{ column.label }}
                </label>
            </div>
        </div>
    </div>
</template>

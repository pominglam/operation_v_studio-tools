<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import type { CustomerRetentionRfmCatalogRow } from '../../types/customerRetention';

const props = defineProps<{
    groupKey: string;
    groupName: string;
    catalog: CustomerRetentionRfmCatalogRow[];
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const row = computed<CustomerRetentionRfmCatalogRow | null>(() => {
    return props.catalog.find((item) => item.key === props.groupKey) ?? null;
});

function toggle(): void {
    open.value = !open.value;
}

function onDocumentClick(event: MouseEvent): void {
    if (!root.value?.contains(event.target as Node)) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <span ref="root" class="relative inline-flex">
        <button
            type="button"
            class="rounded-sm text-left text-slate-800 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600"
            :aria-expanded="open"
            :aria-label="`Show RFM rule for ${groupName}`"
            @click.stop="toggle"
        >
            {{ groupName }}
        </button>
        <span
            v-if="open && row"
            role="dialog"
            class="absolute left-0 top-full z-30 mt-1 w-64 rounded-md border border-slate-200 bg-white p-3 text-left shadow-lg"
        >
            <span class="block text-xs font-semibold text-slate-900">{{ row.name }}</span>
            <span class="mt-1 block text-[11px] text-slate-700">{{ row.rule }}</span>
            <span class="mt-1 block text-[11px] text-slate-500">{{ row.who }}</span>
            <span class="mt-2 block text-[10px] text-slate-400">FM = floor((F + M) / 2)</span>
        </span>
    </span>
</template>

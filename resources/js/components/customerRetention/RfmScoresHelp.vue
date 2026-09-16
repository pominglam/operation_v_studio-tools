<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const open = ref(false);
const root = ref<HTMLElement | null>(null);

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
    <span ref="root" class="relative inline-flex align-middle">
        <button
            type="button"
            class="inline-flex h-4 w-4 shrink-0 cursor-help items-center justify-center rounded-full text-[10px] leading-none text-slate-400 hover:bg-slate-200 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-300"
            aria-label="What R, F, and M mean"
            :aria-expanded="open"
            @click.stop="toggle"
        >
            ⓘ
        </button>
        <span
            v-if="open"
            role="tooltip"
            class="absolute right-0 top-full z-30 mt-1 w-72 rounded-md border border-slate-200 bg-white p-2 text-left text-[11px] font-normal normal-case leading-snug tracking-normal text-slate-700 shadow-lg"
        >
            <span class="block font-semibold text-slate-900">R / F / M scores (1–5)</span>
            <span class="mt-1 block"
                ><span class="font-medium">R</span>ecency — how recently they last bought. 5 = most
                recent fifth of this store.</span
            >
            <span class="mt-1 block"
                ><span class="font-medium">F</span>requency — how many eligible orders. 5 = most
                orders in this store.</span
            >
            <span class="mt-1 block"
                ><span class="font-medium">M</span>onetary — total spend before tax. 5 = highest
                spend in this store.</span
            >
            <span class="mt-1 block text-slate-500"
                >FM = floor((F + M) / 2). The RFM name is R plus FM. Click a name for that group’s
                rule.</span
            >
        </span>
    </span>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
  label: string;
}>();

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
      :aria-label="props.label"
      :aria-expanded="open"
      data-testid="column-header-help-button"
      @click.stop="toggle"
    >
      ⓘ
    </button>
    <span
      v-if="open"
      role="tooltip"
      class="absolute left-1/2 top-full z-30 mt-1 w-56 -translate-x-1/2 rounded-md border border-slate-200 bg-white p-2 text-left text-[11px] font-normal normal-case leading-snug tracking-normal text-slate-700 shadow-lg"
      data-testid="column-header-help-popover"
    >
      {{ props.label }}
    </span>
  </span>
</template>

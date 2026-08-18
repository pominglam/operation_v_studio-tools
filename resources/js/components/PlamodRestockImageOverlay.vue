<script setup lang="ts">
defineProps<{
  imageUrl: string;
  alt: string;
}>();

const emit = defineEmits<{
  close: [];
}>();
</script>

<template>
  <div
    class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 p-4"
    data-testid="restock-image-overlay"
    role="dialog"
    aria-modal="true"
    :aria-label="alt"
    @click.self="emit('close')"
  >
    <button
      type="button"
      class="absolute right-4 top-4 rounded-md bg-white/90 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-white"
      data-testid="restock-image-overlay-close"
      @click="emit('close')"
    >
      Close
    </button>
    <img
      :src="imageUrl"
      :alt="alt"
      class="max-h-[90vh] max-w-[min(90vw,960px)] rounded-lg bg-white object-contain shadow-2xl"
    />
  </div>
</template>

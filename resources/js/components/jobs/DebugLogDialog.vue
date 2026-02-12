<script setup lang="ts">
import BatchItemDebugLog from './BatchItemDebugLog.vue';

const props = defineProps<{
    open: boolean;
    title: string;
    subtitle?: string | null;
    debugLog: string;
    onClose: () => void;
}>();
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="onClose"
        >
            <div class="flex max-h-[92vh] w-full max-w-5xl flex-col rounded-lg bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 p-4">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-slate-900">{{ title }}</div>
                        <div v-if="subtitle" class="mt-1 truncate text-xs text-slate-600">{{ subtitle }}</div>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100"
                        @click="onClose"
                    >
                        Close
                    </button>
                </div>

                <div class="flex-1 overflow-hidden p-4">
                    <BatchItemDebugLog :debug-log="debugLog" max-height="72vh" />
                </div>
            </div>
        </div>
    </Teleport>
</template>


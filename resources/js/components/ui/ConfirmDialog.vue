<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    open: boolean;
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'primary';
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

function onKeyDown(e: KeyboardEvent): void {
    if (!props.open) return;
    if (e.key === 'Escape') emit('cancel');
}

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="emit('cancel')"
        >
            <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ title }}</div>
                        <div class="mt-1 text-sm text-slate-600">{{ message }}</div>
                    </div>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-slate-500 hover:bg-slate-100"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        Close
                    </button>
                </div>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('cancel')"
                    >
                        {{ cancelText ?? 'Cancel' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :class="
                            variant === 'danger'
                                ? 'bg-rose-600 hover:bg-rose-700'
                                : 'bg-slate-900 hover:bg-slate-800'
                        "
                        :disabled="busy"
                        @click="emit('confirm')"
                    >
                        {{ busy ? 'Working…' : (confirmText ?? 'Confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

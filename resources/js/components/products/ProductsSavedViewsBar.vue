<script setup lang="ts">
import { computed, ref } from 'vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import type { ProductsSavedView } from '../../lib/productsSavedViews';

const props = defineProps<{
    views: ProductsSavedView[];
    activeViewId: string | null;
    isDirty: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'apply', id: string): void;
    (e: 'save', name: string): void;
    (e: 'update'): void;
    (e: 'delete', id: string): void;
}>();

const saveOpen = ref(false);
const saveName = ref('');
const saveError = ref<string | null>(null);
const deleteOpen = ref(false);

const selectedId = computed<string>(() => props.activeViewId ?? '');
const activeView = computed<ProductsSavedView | null>(
    () => props.views.find((view) => view.id === props.activeViewId) ?? null,
);

function onSelect(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    if (value === '') return;
    emit('apply', value);
}

function openSave(): void {
    saveName.value = activeView.value?.name ?? '';
    saveError.value = null;
    saveOpen.value = true;
}

function confirmSave(): void {
    const name = saveName.value.trim();
    if (name === '') {
        saveError.value = 'Name is required.';
        return;
    }
    emit('save', name);
    saveOpen.value = false;
}

function requestDelete(): void {
    if (!props.activeViewId) return;
    deleteOpen.value = true;
}

function confirmDelete(): void {
    if (!props.activeViewId) return;
    emit('delete', props.activeViewId);
    deleteOpen.value = false;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-testid="products-saved-views">
        <label class="sr-only" for="products-saved-views-select">Saved views</label>
        <select
            id="products-saved-views-select"
            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
            :value="selectedId"
            :disabled="busy"
            data-testid="products-saved-views-select"
            @change="onSelect"
        >
            <option value="">Saved views</option>
            <option v-for="view in views" :key="view.id" :value="view.id">
                {{ view.name }}
            </option>
        </select>
        <button
            class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
            type="button"
            :disabled="busy"
            data-testid="products-saved-views-save"
            @click="openSave"
        >
            Save view
        </button>
        <button
            class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
            type="button"
            :disabled="busy || !activeViewId || !isDirty"
            data-testid="products-saved-views-update"
            @click="emit('update')"
        >
            Update
        </button>
        <button
            class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
            type="button"
            :disabled="busy || !activeViewId"
            data-testid="products-saved-views-delete"
            @click="requestDelete"
        >
            Delete
        </button>

        <Teleport to="body">
            <div
                v-if="saveOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="products-save-view-title"
                data-testid="products-saved-views-save-dialog"
                @click.self="saveOpen = false"
            >
                <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl">
                    <h2 id="products-save-view-title" class="text-sm font-semibold text-slate-900">
                        Save current view
                    </h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Stores the current filters, search, sort, and visible columns under a name
                        for every operator.
                    </p>
                    <label
                        class="mt-3 block text-xs font-semibold uppercase tracking-wide text-slate-600"
                    >
                        Name
                        <input
                            v-model="saveName"
                            class="mt-1 w-full rounded-md border border-slate-200 px-3 py-2 text-sm font-normal normal-case text-slate-900"
                            type="text"
                            maxlength="40"
                            data-testid="products-saved-views-name"
                            @keydown.enter.prevent="confirmSave"
                        />
                    </label>
                    <p v-if="saveError" class="mt-2 text-sm text-rose-700">{{ saveError }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button
                            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50"
                            type="button"
                            @click="saveOpen = false"
                        >
                            Cancel
                        </button>
                        <button
                            class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                            type="button"
                            data-testid="products-saved-views-save-confirm"
                            @click="confirmSave"
                        >
                            Save
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmDialog
            :open="deleteOpen"
            title="Delete saved view"
            :message="
                activeView
                    ? `Delete “${activeView.name}”? This removes the view for everyone. The product list is unchanged.`
                    : 'Delete this saved view for everyone?'
            "
            confirm-text="Delete"
            variant="danger"
            @confirm="confirmDelete"
            @cancel="deleteOpen = false"
        />
    </div>
</template>

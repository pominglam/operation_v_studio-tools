<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import { api, extractApiError } from '../lib/api';
import type { StoreMarketingNote, StoreMarketingNoteWrite } from '../types/storeMarketingNote';

const emptyForm = (): StoreMarketingNoteWrite => ({
    name: '',
    happened_on: '',
    notes: '',
});

const rows = ref<StoreMarketingNote[]>([]);
const search = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const dialogOpen = ref(false);
const editing = ref<StoreMarketingNote | null>(null);
const form = ref<StoreMarketingNoteWrite>(emptyForm());
const deleteTarget = ref<StoreMarketingNote | null>(null);
const deleting = ref(false);

const dialogTitle = computed(() =>
    editing.value === null ? 'Add marketing note' : 'Edit marketing note',
);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get<{ data: StoreMarketingNote[] }>(
            '/api/v1/store-marketing-notes',
            {
                params: search.value.trim() === '' ? {} : { search: search.value.trim() },
            },
        );
        rows.value = data.data;
    } catch (err) {
        error.value = extractApiError(err);
    } finally {
        loading.value = false;
    }
}

function openCreate(): void {
    editing.value = null;
    form.value = emptyForm();
    dialogOpen.value = true;
}

function openEdit(row: StoreMarketingNote): void {
    editing.value = row;
    form.value = {
        name: row.name,
        happened_on: row.happened_on,
        notes: row.notes ?? '',
    };
    dialogOpen.value = true;
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    const payload = {
        name: form.value.name.trim(),
        happened_on: form.value.happened_on,
        notes: form.value.notes.trim() === '' ? null : form.value.notes.trim(),
    };
    try {
        if (editing.value === null) {
            await api.post('/api/v1/store-marketing-notes', payload);
        } else {
            await api.patch(`/api/v1/store-marketing-notes/${editing.value.id}`, payload);
        }
        dialogOpen.value = false;
        await load();
    } catch (err) {
        error.value = extractApiError(err);
    } finally {
        saving.value = false;
    }
}

async function confirmDelete(): Promise<void> {
    const row = deleteTarget.value;
    if (row === null) return;
    deleting.value = true;
    error.value = null;
    try {
        await api.delete(`/api/v1/store-marketing-notes/${row.id}`);
        deleteTarget.value = null;
        await load();
    } catch (err) {
        error.value = extractApiError(err);
    } finally {
        deleting.value = false;
    }
}

onMounted(() => {
    void load();
});
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
                    Marketing notes
                </h1>
                <p class="mt-1 text-sm text-slate-600">
                    Posts, videos, ads, and other marketing that happened on a day. These are not
                    that day’s sales — impact usually shows up later.
                </p>
            </div>
            <button
                type="button"
                class="h-9 rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800"
                @click="openCreate"
            >
                Add note
            </button>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <label class="flex min-w-[14rem] flex-col gap-1 text-sm">
                <span class="text-slate-600">Search</span>
                <input
                    v-model="search"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    placeholder="Restock, thenhanfamily…"
                    @keydown.enter="load"
                />
            </label>
            <button
                type="button"
                class="h-9 rounded-md border border-slate-200 px-3 text-sm text-slate-700 hover:bg-slate-50"
                :disabled="loading"
                @click="load"
            >
                Search
            </button>
        </div>

        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
        <p v-else-if="loading" class="text-sm text-slate-500">Loading…</p>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">What</th>
                        <th class="px-3 py-2 font-medium">Notes</th>
                        <th class="px-3 py-2 font-medium" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums text-slate-700">
                            {{ row.happened_on }}
                        </td>
                        <td class="px-3 py-2 font-medium text-slate-900">{{ row.name }}</td>
                        <td class="max-w-md px-3 py-2 text-slate-600">{{ row.notes ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <div
                                class="flex flex-col items-end gap-1 sm:flex-row sm:justify-end sm:gap-3"
                            >
                                <button
                                    type="button"
                                    class="text-sm text-slate-700 underline hover:text-slate-900"
                                    @click="openEdit(row)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="text-sm text-red-700 underline hover:text-red-900"
                                    @click="deleteTarget = row"
                                >
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="4" class="px-3 py-6 text-center text-slate-500">
                            No marketing notes yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="dialogOpen"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="dialogOpen = false"
        >
            <form
                class="w-full max-w-lg space-y-3 rounded-lg bg-white p-4 shadow-xl"
                @submit.prevent="save"
            >
                <h2 class="text-sm font-semibold text-slate-900">{{ dialogTitle }}</h2>
                <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-slate-600">When</span>
                    <input
                        v-model="form.happened_on"
                        type="date"
                        required
                        class="rounded-md border border-slate-200 px-2 py-1"
                    />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-slate-600">What</span>
                    <input
                        v-model="form.name"
                        required
                        class="rounded-md border border-slate-200 px-2 py-1"
                        placeholder="Restock post, video + ads…"
                    />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-slate-600">Notes</span>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="rounded-md border border-slate-200 px-2 py-1"
                    />
                </label>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-3 py-1.5 text-sm"
                        @click="dialogOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-60"
                        :disabled="saving"
                    >
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </form>
        </div>

        <ConfirmDialog
            :open="deleteTarget !== null"
            title="Delete marketing note"
            :message="deleteTarget ? `Delete “${deleteTarget.name}”?` : ''"
            confirm-text="Delete"
            variant="danger"
            :busy="deleting"
            @confirm="confirmDelete"
            @cancel="deleteTarget = null"
        />
    </section>
</template>

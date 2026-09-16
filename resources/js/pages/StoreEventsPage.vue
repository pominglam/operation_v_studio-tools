<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import StoreEventCalendar from '../components/storeEvents/StoreEventCalendar.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import { api, extractApiError } from '../lib/api';
import { applyStoreEventInclusion } from '../lib/storeEvents';
import type { StoreEvent, StoreEventDayOrder, StoreEventWrite } from '../types/storeEvent';

const emptyForm = (): StoreEventWrite => ({
    name: '',
    starts_on: '',
    ends_on: '',
    notes: '',
    cancelled: false,
});

const rows = ref<StoreEvent[]>([]);
const search = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const dialogOpen = ref(false);
const editing = ref<StoreEvent | null>(null);
const form = ref<StoreEventWrite>(emptyForm());
const deleteTarget = ref<StoreEvent | null>(null);
const deleting = ref(false);
const expandedEventIds = ref<string[]>([]);
const expandedDayKeys = ref<string[]>([]);
const expandedOrderIds = ref<number[]>([]);
const taggingOrderId = ref<number | null>(null);

const dialogTitle = computed(() =>
    editing.value === null ? 'Add store event' : 'Edit store event',
);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get<{ data: StoreEvent[] }>('/api/v1/store-events', {
            params: search.value.trim() === '' ? {} : { search: search.value.trim() },
        });
        rows.value = data.data;
    } catch (err) {
        error.value = extractApiError(err);
    } finally {
        loading.value = false;
    }
}

function toggleEvent(id: string): void {
    expandedEventIds.value = expandedEventIds.value.includes(id)
        ? expandedEventIds.value.filter((item) => item !== id)
        : [...expandedEventIds.value, id];
}

function toggleDay(key: string): void {
    expandedDayKeys.value = expandedDayKeys.value.includes(key)
        ? expandedDayKeys.value.filter((item) => item !== key)
        : [...expandedDayKeys.value, key];
}

function toggleOrderLines(orderId: number): void {
    expandedOrderIds.value = expandedOrderIds.value.includes(orderId)
        ? expandedOrderIds.value.filter((item) => item !== orderId)
        : [...expandedOrderIds.value, orderId];
}

async function setIncluded(payload: {
    event: StoreEvent;
    order: StoreEventDayOrder;
    included: boolean;
}): Promise<void> {
    if (taggingOrderId.value === payload.order.id) {
        return;
    }
    const previous = rows.value;
    rows.value = applyStoreEventInclusion(
        rows.value,
        payload.event.id,
        payload.order.id,
        payload.included,
    );
    taggingOrderId.value = payload.order.id;
    error.value = null;
    try {
        await api.post(`/api/v1/store-events/${payload.event.id}/orders`, {
            order_ids: [payload.order.id],
            included: payload.included,
        });
    } catch (err) {
        rows.value = previous;
        error.value = extractApiError(err);
    } finally {
        taggingOrderId.value = null;
    }
}

function openCreate(): void {
    editing.value = null;
    form.value = emptyForm();
    dialogOpen.value = true;
}

function openEdit(row: StoreEvent): void {
    editing.value = row;
    form.value = {
        name: row.name,
        starts_on: row.starts_on,
        ends_on: row.ends_on,
        notes: row.notes ?? '',
        cancelled: row.cancelled,
    };
    dialogOpen.value = true;
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    const payload = {
        name: form.value.name.trim(),
        starts_on: form.value.starts_on,
        ends_on: form.value.ends_on,
        notes: form.value.notes.trim() === '' ? null : form.value.notes.trim(),
        cancelled: form.value.cancelled,
    };
    try {
        if (editing.value === null) {
            await api.post('/api/v1/store-events', payload);
        } else {
            await api.patch(`/api/v1/store-events/${editing.value.id}`, payload);
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
        await api.delete(`/api/v1/store-events/${row.id}`);
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
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Store events</h1>
                <p class="mt-1 text-sm text-slate-600">
                    In-store community events (not TCG+). Check an order to count it in the event.
                    Gray rows are that day’s other eligible sales. Amounts are revenue before tax.
                    Sheet Other (posts, videos, ads) lives on
                    <RouterLink class="underline" to="/marketing-notes">Marketing notes</RouterLink>
                    — those dates are not Event $.
                </p>
            </div>
            <button
                type="button"
                class="h-9 rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800"
                @click="openCreate"
            >
                Add event
            </button>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <label class="flex min-w-[14rem] flex-col gap-1 text-sm">
                <span class="text-slate-600">Search name</span>
                <input
                    v-model="search"
                    class="rounded-md border border-slate-200 px-2 py-1"
                    placeholder="Washless, build night…"
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

        <StoreEventCalendar
            :events="rows"
            :loading="loading"
            currency="CAD"
            :expanded-event-ids="expandedEventIds"
            :expanded-day-keys="expandedDayKeys"
            :expanded-order-ids="expandedOrderIds"
            @toggle-event="toggleEvent"
            @toggle-day="toggleDay"
            @toggle-order-lines="toggleOrderLines"
            @edit="openEdit"
            @delete="deleteTarget = $event"
            @set-included="setIncluded"
        />

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
                    <span class="text-slate-600">Name</span>
                    <input
                        v-model="form.name"
                        required
                        class="rounded-md border border-slate-200 px-2 py-1"
                    />
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-slate-600">Starts</span>
                        <input
                            v-model="form.starts_on"
                            type="date"
                            required
                            class="rounded-md border border-slate-200 px-2 py-1"
                            @change="
                                form.ends_on === '' || form.ends_on < form.starts_on
                                    ? (form.ends_on = form.starts_on)
                                    : null
                            "
                        />
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-slate-600">Ends</span>
                        <input
                            v-model="form.ends_on"
                            type="date"
                            required
                            class="rounded-md border border-slate-200 px-2 py-1"
                        />
                    </label>
                </div>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-slate-600">Notes</span>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="rounded-md border border-slate-200 px-2 py-1"
                        placeholder="Sheet totals until orders are tagged"
                    />
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="form.cancelled" type="checkbox" />
                    Cancelled
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
            title="Delete store event"
            :message="
                deleteTarget
                    ? `Delete “${deleteTarget.name}”? This does not change Shopify orders.`
                    : ''
            "
            confirm-text="Delete"
            variant="danger"
            :busy="deleting"
            @confirm="confirmDelete"
            @cancel="deleteTarget = null"
        />
    </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { formatTorontoDateTime } from '../lib/datetime';

type TcgEvent = {
  id: string;
  source: string;
  external_event_id: number;
  store_name: string;
  store_url: string | null;
  store_sns_url: string | null;
  phone_number: string | null;
  location: {
    street_address: string | null;
    city: string | null;
    pref_code: string | null;
    postcode: string | null;
    lat: string | null;
    lng: string | null;
    maps_url: string | null;
  };
  event_name: string;
  event_url: string;
  start_datetime: string | null;
  timezone: string | null;
  format: string | null;
  excerpt: string | null;
  lottery_method: string | null;
  entry_fee: string | null;
  entry_fee_currency_code: string | null;
  capacity: number | null;
  applicants: number | null;
  status: string | null;
  fetched_at: string | null;
};

type IndexResponse = {
  data: TcgEvent[];
  meta?: {
    latest_fetched_at?: string | null;
  };
};

function todayLocalYmd(): string {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

const startDate = ref<string>(todayLocalYmd());
const streetAddress = ref<string>('montreal');
const search = ref<string>('');
const status = ref<string>('');
const format = ref<string>('');
const perPage = ref<number>(100);
const hideZeroApplicants = ref<boolean>(true);

const events = ref<TcgEvent[]>([]);
const latestFetchedAt = ref<string | null>(null);
const loading = ref<boolean>(false);
const refreshing = ref<boolean>(false);
const errorMessage = ref<string | null>(null);

const formatOptions = computed<string[]>(() => {
  const set = new Set<string>();
  for (const e of events.value) {
    if (e.format) set.add(e.format);
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b));
});

async function fetchEvents(): Promise<void> {
  loading.value = true;
  errorMessage.value = null;
  try {
    const res = await api.get<IndexResponse>('/api/v1/tcg/events', {
      params: {
        per_page: perPage.value,
        start_date: startDate.value,
        search: search.value.trim() || undefined,
        status: status.value || undefined,
        format: format.value || undefined,
        hide_zero_applicants: hideZeroApplicants.value ? 1 : 0,
      },
    });
    events.value = res.data.data ?? [];
    latestFetchedAt.value = res.data.meta?.latest_fetched_at ?? null;
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err);
  } finally {
    loading.value = false;
  }
}

async function refreshNow(): Promise<void> {
  refreshing.value = true;
  errorMessage.value = null;
  try {
    await api.post(
      '/api/v1/tcg/events/refresh',
      {
        start_date: startDate.value,
        street_address: streetAddress.value.trim() || 'montreal',
        pref_code: 'CA-QC',
        country_code: 'CA',
        game_title_id: 16,
        limit: 100,
      },
      { timeout: 60_000 },
    );
    await fetchEvents();
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err);
  } finally {
    refreshing.value = false;
  }
}

watch([startDate, status, format, hideZeroApplicants], async () => {
  await fetchEvents();
});

onMounted(async () => {
  await fetchEvents();
});
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-1">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">TCG Events</h1>
        <p class="text-sm text-slate-600">
          Bandai TCG+ events (cached until manual refresh).
          <span v-if="latestFetchedAt" class="ml-2 text-slate-500">
            Last refresh: {{ formatTorontoDateTime(latestFetchedAt) }}
          </span>
        </p>
      </div>

      <div class="flex flex-wrap items-end gap-2">
        <div class="flex flex-col gap-1">
          <label for="tcg-start-date" class="text-xs font-medium text-slate-600">Start date</label>
          <input
            id="tcg-start-date"
            v-model="startDate"
            type="date"
            class="h-9 w-[160px] rounded-md border border-slate-300 bg-white px-2 text-sm"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="tcg-location" class="text-xs font-medium text-slate-600">Location</label>
          <input
            id="tcg-location"
            v-model="streetAddress"
            type="text"
            placeholder="montreal"
            class="h-9 w-[180px] rounded-md border border-slate-300 bg-white px-2 text-sm"
          />
        </div>

        <button
          type="button"
          class="h-9 rounded-md bg-slate-900 px-3 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="refreshing"
          @click="refreshNow"
        >
          {{ refreshing ? 'Refreshing…' : 'Refresh' }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap items-end gap-2">
      <div class="flex flex-col gap-1">
        <label for="tcg-search" class="text-xs font-medium text-slate-600">Search</label>
        <input
          id="tcg-search"
          v-model="search"
          type="text"
          placeholder="Store, address, city, event name…"
          class="h-9 w-[320px] rounded-md border border-slate-300 bg-white px-2 text-sm"
          @keydown.enter.prevent="fetchEvents"
        />
      </div>

      <button
        type="button"
        class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="loading"
        @click="fetchEvents"
      >
        {{ loading ? 'Loading…' : 'Search' }}
      </button>

      <div class="flex flex-col gap-1">
        <label for="tcg-status" class="text-xs font-medium text-slate-600">Status</label>
        <select
          id="tcg-status"
          v-model="status"
          class="h-9 w-[160px] rounded-md border border-slate-300 bg-white px-2 text-sm"
        >
          <option value="">All</option>
          <option value="accepting">Accepting</option>
          <option value="accepted">Accepted</option>
          <option value="winner fixed">Winner fixed</option>
          <option value="running">Running</option>
          <option value="finished">Finished</option>
        </select>
      </div>

      <div class="flex flex-col gap-1">
        <label for="tcg-format" class="text-xs font-medium text-slate-600">Format</label>
        <select
          id="tcg-format"
          v-model="format"
          class="h-9 w-[180px] rounded-md border border-slate-300 bg-white px-2 text-sm"
        >
          <option value="">All</option>
          <option v-for="opt in formatOptions" :key="opt" :value="opt">{{ opt }}</option>
        </select>
      </div>

      <label
        for="tcg-hide-zero-applicants"
        class="ml-2 flex select-none items-center gap-2 text-xs font-medium text-slate-700"
      >
        <input
          id="tcg-hide-zero-applicants"
          v-model="hideZeroApplicants"
          type="checkbox"
          class="h-4 w-4 rounded border-slate-300"
        />
        Hide 0 applicants
      </label>
    </div>

    <div v-if="errorMessage" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
      {{ errorMessage }}
    </div>

    <div class="overflow-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full min-w-[1400px] text-left text-sm">
        <thead class="sticky top-0 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            <th class="px-3 py-2">Store</th>
            <th class="px-3 py-2">Location</th>
            <th class="px-3 py-2">Event</th>
            <th class="px-3 py-2">Date / Time</th>
            <th class="px-3 py-2">Format</th>
            <th class="px-3 py-2">Excerpt</th>
            <th class="px-3 py-2">Lottery method</th>
            <th class="px-3 py-2">Entry fee</th>
            <th class="px-3 py-2">Capacity</th>
            <th class="px-3 py-2">Applicants</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="events.length === 0" class="border-t border-slate-200">
            <td class="px-3 py-6 text-center text-slate-500" colspan="11">
              No events found. Try refreshing.
            </td>
          </tr>
          <tr v-for="e in events" :key="e.id" class="border-t border-slate-200 align-top">
            <td class="px-3 py-2">
              <div class="font-medium text-slate-900">{{ e.store_name }}</div>
              <div class="text-xs text-slate-500">
                <span v-if="e.phone_number">{{ e.phone_number }}</span>
                <span v-if="e.store_url" class="ml-2">
                  <a :href="e.store_url" target="_blank" rel="noreferrer" class="hover:underline">Website</a>
                </span>
              </div>
            </td>
            <td class="px-3 py-2">
              <div class="text-slate-800">
                <div>{{ e.location.street_address || '—' }}</div>
                <div class="text-xs text-slate-500">
                  {{ [e.location.city, e.location.pref_code, e.location.postcode].filter(Boolean).join(', ') }}
                </div>
              </div>
              <div v-if="e.location.maps_url" class="mt-1 text-xs">
                <a :href="e.location.maps_url" target="_blank" rel="noreferrer" class="text-slate-700 hover:underline">
                  Open in Google Maps
                </a>
              </div>
            </td>
            <td class="px-3 py-2">
              <a :href="e.event_url" target="_blank" rel="noreferrer" class="font-medium text-slate-900 hover:underline">
                {{ e.event_name }}
              </a>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">
              {{ formatTorontoDateTime(e.start_datetime) }}
            </td>
            <td class="px-3 py-2">{{ e.format || '—' }}</td>
            <td class="px-3 py-2">
              <div class="max-w-[420px] whitespace-pre-wrap text-xs text-slate-700">
                {{ e.excerpt || '—' }}
              </div>
            </td>
            <td class="px-3 py-2">{{ e.lottery_method || '—' }}</td>
            <td class="px-3 py-2 whitespace-nowrap">
              <span v-if="e.entry_fee">
                {{ e.entry_fee }} {{ (e.entry_fee_currency_code || '').toUpperCase() }}
              </span>
              <span v-else>—</span>
            </td>
            <td class="px-3 py-2">{{ e.capacity ?? '—' }}</td>
            <td class="px-3 py-2">{{ e.applicants ?? '—' }}</td>
            <td class="px-3 py-2">
              <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-800">
                {{ e.status || '—' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>


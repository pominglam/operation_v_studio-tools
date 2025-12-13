<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { formatLocalDateTime } from '../lib/datetime';
import MultiSelectFilter, { type MultiSelectOption } from '../components/ui/MultiSelectFilter.vue';
import PaginationControls from '../components/ui/PaginationControls.vue';

type Quote = {
  site_key: string;
  site_name: string;
  status: 'found' | 'not_found' | 'error';
  availability: 'in_stock' | 'sold_out' | null;
  currency: string;
  price: string | null;
  original_price: string | null;
  product_url: string | null;
  error_message: string | null;
  fetched_at: string;
};

type ProductResearch = {
  id: string;
  sku: string;
  barcode: string | null;
  description: string;
  price_researched_at: string | null;
  expired: boolean;
  cost: string | null;
  quotes: Quote[];
};

type Paginated<T> = {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

const loading = ref(false);
const running = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const items = ref<ProductResearch[]>([]);
const activeRunId = ref<string | null>(null);
const polling = ref(false);
const destroyed = ref(false);
const runStatus = ref<{
  id: string;
  status: 'queued' | 'running' | 'completed' | 'failed';
  total_products: number;
  processed_products: number;
  refreshed_products: number;
  skipped_fresh_products: number;
  total_sites: number;
  processed_sites: number;
  quotes_written: number;
  started_at: string | null;
  finished_at: string | null;
  error_message: string | null;
} | null>(null);

const isRunActive = computed<boolean>(() => {
  return runStatus.value?.status === 'queued' || runStatus.value?.status === 'running';
});

const isBusy = computed<boolean>(() => loading.value || running.value || isRunActive.value);

type ResearchSortKey = 'sku' | 'description' | 'price_researched_at' | 'cost';

const search = ref('');
const perPage = ref(50);
const page = ref(1);
const sortBy = ref<ResearchSortKey>('price_researched_at');
const sortDir = ref<'asc' | 'desc'>('desc');

const freshnessOptions: MultiSelectOption[] = [
  { value: 'fresh', label: 'Fresh' },
  { value: 'expired', label: 'Expired' },
];
const freshness = ref<string[]>([]);

const quoteStatusOptions: MultiSelectOption[] = [
  { value: 'found', label: 'Found' },
  { value: 'not_found', label: 'Not found' },
  { value: 'error', label: 'Error' },
];
const quoteStatuses = ref<string[]>([]);

const quoteAvailabilityOptions: MultiSelectOption[] = [
  { value: 'in_stock', label: 'In stock' },
  { value: 'sold_out', label: 'Sold out' },
];
const quoteAvailabilities = ref<string[]>([]);

const sites = [
  { key: 'gundam_hangar', name: 'Gundam Hangar' },
  { key: 'panda_hobby', name: 'Panda Hobby' },
  { key: 'canadian_gundam', name: 'Canadian Gundam' },
  { key: 'hobby_bee', name: 'Hobby Bee' },
  { key: 'hobby_wholesale', name: 'HobbyWholesale' },
  { key: 'meeplemart', name: 'Meeplemart' },
  { key: 'hobby_sense', name: 'Hobby Sense' },
];

const quoteSiteOptions: MultiSelectOption[] = sites.map((s) => ({ value: s.key, label: s.name }));
const quoteSites = ref<string[]>([]);

const meta = ref<Paginated<ProductResearch>['meta'] | null>(null);
const total = computed<number>(() => meta.value?.total ?? 0);
const currentPage = computed<number>(() => meta.value?.current_page ?? page.value);
const lastPage = computed<number>(() => meta.value?.last_page ?? 1);

function quoteFor(product: ProductResearch, siteKey: string): Quote | null {
  return product.quotes.find((q) => q.site_key === siteKey) ?? null;
}

function parseMoney(value: string | null): number | null {
  if (!value) return null;
  const cleaned = value.replace(/[^0-9.-]/g, '');
  if (!cleaned) return null;
  const n = Number.parseFloat(cleaned);
  return Number.isFinite(n) ? n : null;
}

function formatMoney(value: number | null): string | null {
  if (value === null) return null;
  return value.toFixed(2);
}

function averagePriceOnline(p: ProductResearch): string | null {
  const nums = p.quotes
    .filter((q) => q.status === 'found')
    .map((q) => parseMoney(q.price))
    .filter((n): n is number => n !== null);

  if (nums.length === 0) return null;
  const avg = nums.reduce((a, b) => a + b, 0) / nums.length;
  return formatMoney(avg);
}

function costTimes(p: ProductResearch, factor: number): string | null {
  const n = parseMoney(p.cost);
  if (n === null) return null;
  return formatMoney(n * factor);
}

function buildProductsUrl(): string {
  const params = new URLSearchParams();
  params.set('per_page', String(perPage.value));
  params.set('page', String(page.value));
  params.set('sort_by', sortBy.value);
  params.set('sort_dir', sortDir.value);
  const s = search.value.trim();
  if (s) params.set('search', s);

  for (const v of freshness.value) params.append('freshness[]', v);
  for (const v of quoteSites.value) params.append('quote_sites[]', v);
  for (const v of quoteStatuses.value) params.append('quote_statuses[]', v);
  for (const v of quoteAvailabilities.value) params.append('quote_availabilities[]', v);

  return `/api/v1/price-research/products?${params.toString()}`;
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  message.value = null;

  try {
    // Use fetch directly here to avoid any adapter/proxy issues during local dev.
    const ctrl = new AbortController();
    const t = window.setTimeout(() => ctrl.abort(), 15000);
    try {
      const r = await fetch(buildProductsUrl(), { signal: ctrl.signal });
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      const json = (await r.json()) as Paginated<ProductResearch>;
      items.value = json.data;
      meta.value = json.meta;
    } finally {
      window.clearTimeout(t);
    }
  } catch (e: unknown) {
    error.value = 'Failed to load price research results.';
  } finally {
    loading.value = false;
  }
}

async function loadLatestRun(): Promise<void> {
  try {
    const r = await fetch('/api/v1/price-research/runs/latest');
    if (!r.ok) return;
    const json = (await r.json()) as { data: typeof runStatus.value };
    runStatus.value = json.data;
    activeRunId.value = json.data?.id ?? null;

    if (json.data && (json.data.status === 'queued' || json.data.status === 'running')) {
      void pollRun(json.data.id);
    }
  } catch {
    // ignore
  }
}

async function pollRun(id: string): Promise<void> {
  if (polling.value) return;
  polling.value = true;
  running.value = true;

  try {
    // Important: keep this function alive while polling. The prior implementation used setTimeout
    // recursion which caused pollRun() to return early, leading to overlapping poll loops and a UI
    // that looks like it's constantly refreshing.
    while (!destroyed.value) {
      try {
        const r = await fetch(`/api/v1/price-research/runs/${id}`);
        if (r.ok) {
          const json = (await r.json()) as { data: NonNullable<typeof runStatus.value> };
          runStatus.value = json.data;

          if (json.data.status !== 'queued' && json.data.status !== 'running') {
            await load();
            break;
          }
        }
      } catch {
        // ignore; we'll retry after delay
      }

      await new Promise((resolve) => window.setTimeout(resolve, 1500));
    }
  } finally {
    running.value = false;
    polling.value = false;
  }
}

async function run(force: boolean): Promise<void> {
  running.value = true;
  error.value = null;
  message.value = null;

  try {
    const res = await api.post(
      '/api/v1/price-research/run',
      { force },
      { validateStatus: () => true },
    );

    const runId = res.data?.run_id as string | undefined;
    if (runId) {
      activeRunId.value = runId;
      await pollRun(runId);
    }

    if (res.status === 202) {
      message.value = 'Queued price research job. Showing live status below…';
      return;
    }

    message.value = `Processed ${res.data.data.processed}. Refreshed ${res.data.data.refreshed}. Skipped fresh ${res.data.data.skipped_fresh}.`;
    await load();
  } catch (e: unknown) {
    error.value = 'Failed to run price research.';
  } finally {
    running.value = false;
  }
}

onMounted(() => {
  void load();
  void loadLatestRun();
});

onBeforeUnmount(() => {
  destroyed.value = true;
});

function onSortChange(next: ResearchSortKey): void {
  if (sortBy.value === next) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    return;
  }
  sortBy.value = next;
  sortDir.value = next === 'price_researched_at' ? 'desc' : 'asc';
}

function sortIndicator(key: ResearchSortKey): string {
  if (sortBy.value !== key) return '';
  return sortDir.value === 'asc' ? ' ▲' : ' ▼';
}

function onPageChange(next: number): void {
  page.value = Math.max(1, next);
}

let searchTimer: number | null = null;
watch([search, perPage, freshness, quoteSites, quoteStatuses, quoteAvailabilities, sortBy, sortDir], () => {
  page.value = 1;
  if (searchTimer) window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => void load(), 250);
});
watch(page, () => void load());
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold">Price research</h1>
        <p class="mt-1 text-sm text-slate-600">
          Fetch competitor prices for each product (prices are valid for 14 days; expired products should be refreshed).
        </p>
      </div>

      <div class="flex items-center gap-2">
        <button
          class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
          type="button"
          :disabled="isBusy"
          @click="load"
        >
          Refresh
        </button>
        <button
          class="inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-50"
          type="button"
          :disabled="isBusy"
          @click="run(false)"
        >
          {{ isBusy ? 'Running…' : 'Run (expired only)' }}
        </button>
        <button
          class="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-slate-50 disabled:opacity-50"
          type="button"
          :disabled="isBusy"
          @click="run(true)"
        >
          Force refresh all
        </button>
      </div>
    </div>

    <div v-if="error" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
      {{ error }}
    </div>
    <div v-if="message" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
      {{ message }}
    </div>

    <div v-if="runStatus" class="rounded-lg border border-slate-200 bg-white p-4">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-sm font-semibold text-slate-900">Run status</div>
          <div class="mt-1 text-sm text-slate-600">
            <span class="font-medium">Status:</span> {{ runStatus.status }}
            <span v-if="runStatus.started_at"> • <span class="font-medium">Started:</span> {{ formatLocalDateTime(runStatus.started_at) }}</span>
            <span v-if="runStatus.finished_at"> • <span class="font-medium">Finished:</span> {{ formatLocalDateTime(runStatus.finished_at) }}</span>
          </div>
          <div v-if="runStatus.error_message" class="mt-2 text-sm text-rose-700">{{ runStatus.error_message }}</div>
        </div>
        <div class="text-right text-sm text-slate-600">
          <div><span class="font-medium text-slate-900">{{ runStatus.processed_products }}</span> / {{ runStatus.total_products }} products</div>
          <div><span class="font-medium text-slate-900">{{ runStatus.processed_sites }}</span> / {{ runStatus.total_products * runStatus.total_sites }} site checks</div>
        </div>
      </div>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-4">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-6 md:items-end">
        <div class="md:col-span-2">
          <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Search</label>
          <input
            v-model="search"
            class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900"
            type="text"
            placeholder="Search SKU / barcode / description…"
          />
        </div>

        <MultiSelectFilter v-model="freshness" label="Status" :options="freshnessOptions" placeholder="Fresh + Expired" />
        <MultiSelectFilter v-model="quoteSites" label="Site" :options="quoteSiteOptions" placeholder="All sites" />
        <MultiSelectFilter v-model="quoteStatuses" label="Quote status" :options="quoteStatusOptions" placeholder="All" />
        <MultiSelectFilter
          v-model="quoteAvailabilities"
          label="Availability"
          :options="quoteAvailabilityOptions"
          placeholder="All"
        />
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-6 md:items-end">
        <div class="md:col-span-2 text-sm text-slate-600">
          Showing <span class="font-medium text-slate-900">{{ items.length }}</span> of
          <span class="font-medium text-slate-900">{{ total }}</span>
        </div>
        <div class="md:col-span-2"></div>
        <div class="md:col-span-2">
          <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Per page</label>
          <select v-model.number="perPage" class="mt-1 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm">
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </div>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <th class="px-4 py-3">
                <button type="button" class="hover:underline" @click="onSortChange('sku')">SKU{{ sortIndicator('sku') }}</button>
              </th>
              <th class="px-4 py-3">
                <button type="button" class="hover:underline" @click="onSortChange('description')">
                  Description{{ sortIndicator('description') }}
                </button>
              </th>
              <th class="px-4 py-3">
                <button type="button" class="hover:underline" @click="onSortChange('price_researched_at')">
                  Last updated{{ sortIndicator('price_researched_at') }}
                </button>
              </th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3 text-right">
                <button type="button" class="hover:underline" @click="onSortChange('cost')">
                  Cost to buy{{ sortIndicator('cost') }}
                </button>
              </th>
              <th class="px-4 py-3 text-right">Average price online</th>
              <th class="px-4 py-3 text-right">1.5x</th>
              <th v-for="s in sites" :key="s.key" class="px-4 py-3 text-right">{{ s.name }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="items.length === 0">
              <td class="px-4 py-4 text-slate-600" :colspan="7 + sites.length">No products found.</td>
            </tr>

            <tr v-for="p in items" :key="p.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-medium text-slate-900">{{ p.sku }}</td>
              <td class="px-4 py-3 text-slate-700">{{ p.description }}</td>
              <td class="px-4 py-3 text-slate-700">{{ formatLocalDateTime(p.price_researched_at) }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="p.expired ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'"
                >
                  {{ p.expired ? 'Expired' : 'Fresh' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                <span class="font-medium text-slate-900">{{ p.cost ?? '—' }}</span>
              </td>
              <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                <span class="font-medium text-slate-900">{{ averagePriceOnline(p) ?? '—' }}</span>
              </td>
              <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                <span class="font-medium text-slate-900">{{ costTimes(p, 1.5) ?? '—' }}</span>
              </td>

              <td v-for="s in sites" :key="s.key" class="px-4 py-3 text-right tabular-nums text-slate-700">
                <template v-if="quoteFor(p, s.key)">
                  <template v-if="quoteFor(p, s.key)!.status === 'found'">
                    <div class="flex items-baseline justify-end gap-2">
                      <span
                        v-if="quoteFor(p, s.key)!.original_price && quoteFor(p, s.key)!.original_price !== quoteFor(p, s.key)!.price"
                        class="text-xs text-slate-500 line-through"
                      >
                        {{ quoteFor(p, s.key)!.original_price }}
                      </span>
                    <a
                      v-if="quoteFor(p, s.key)!.product_url"
                      class="font-medium underline"
                      :class="quoteFor(p, s.key)!.availability === 'sold_out' ? 'text-rose-700' : 'text-slate-900'"
                      :href="quoteFor(p, s.key)!.product_url!"
                      target="_blank"
                      rel="noreferrer"
                    >
                      {{ quoteFor(p, s.key)!.price ?? '—' }}
                    </a>
                    <span
                      v-else
                      class="font-medium"
                      :class="quoteFor(p, s.key)!.availability === 'sold_out' ? 'text-rose-700' : 'text-slate-900'"
                    >
                      {{ quoteFor(p, s.key)!.price ?? '—' }}
                    </span>
                    </div>
                    <div
                      v-if="quoteFor(p, s.key)!.availability"
                      class="mt-0.5 text-[11px]"
                      :class="quoteFor(p, s.key)!.availability === 'sold_out' ? 'text-rose-700' : 'text-slate-500'"
                    >
                      {{ quoteFor(p, s.key)!.availability === 'in_stock' ? 'In stock' : 'Sold out' }}
                    </div>
                  </template>
                  <template v-else-if="quoteFor(p, s.key)!.status === 'not_found'">
                    <span class="text-slate-400">Not found</span>
                  </template>
                  <template v-else>Error</template>
                </template>
                <template v-else>—</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <PaginationControls :current-page="currentPage" :last-page="lastPage" :total="total" :on-change="onPageChange" />
  </section>
</template>



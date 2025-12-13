<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import { formatLocalDateTime } from '../lib/datetime';

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
  quotes: Quote[];
};

type Paginated<T> = {
  data: T[];
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

const sites = [
  { key: 'gundam_hangar', name: 'Gundam Hangar' },
  { key: 'panda_hobby', name: 'Panda Hobby' },
  { key: 'canadian_gundam', name: 'Canadian Gundam' },
  { key: 'hobby_bee', name: 'Hobby Bee' },
  { key: 'hobby_wholesale', name: 'HobbyWholesale' },
  { key: 'meeplemart', name: 'Meeplemart' },
  { key: 'hobby_sense', name: 'Hobby Sense' },
];

function quoteFor(product: ProductResearch, siteKey: string): Quote | null {
  return product.quotes.find((q) => q.site_key === siteKey) ?? null;
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
      const r = await fetch('/api/v1/price-research/products?per_page=50', { signal: ctrl.signal });
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      const json = (await r.json()) as Paginated<ProductResearch>;
      items.value = json.data;
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

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <div v-if="loading" class="px-4 py-3 text-sm text-slate-600">Loading…</div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
              <th class="px-4 py-3">SKU</th>
              <th class="px-4 py-3">Description</th>
              <th class="px-4 py-3">Last updated</th>
              <th class="px-4 py-3">Status</th>
              <th v-for="s in sites" :key="s.key" class="px-4 py-3 text-right">{{ s.name }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="items.length === 0">
              <td class="px-4 py-4 text-slate-600" :colspan="4 + sites.length">No products found.</td>
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
                      class="font-medium text-slate-900 underline"
                      :href="quoteFor(p, s.key)!.product_url!"
                      target="_blank"
                      rel="noreferrer"
                    >
                      {{ quoteFor(p, s.key)!.price ?? '—' }}
                    </a>
                    <span v-else class="font-medium text-slate-900">{{ quoteFor(p, s.key)!.price ?? '—' }}</span>
                    </div>
                    <div v-if="quoteFor(p, s.key)!.availability" class="mt-0.5 text-[11px] text-slate-500">
                      {{ quoteFor(p, s.key)!.availability === 'in_stock' ? 'In stock' : 'Sold out' }}
                    </div>
                  </template>
                  <template v-else-if="quoteFor(p, s.key)!.status === 'not_found'">Not found</template>
                  <template v-else>Error</template>
                </template>
                <template v-else>—</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>



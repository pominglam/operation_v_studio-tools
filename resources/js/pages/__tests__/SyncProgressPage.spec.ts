import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { nextTick } from 'vue';

import SyncProgressPage from '../SyncProgressPage.vue';

vi.mock('../../lib/api', () => {
  return {
    api: {
      get: vi.fn(),
      post: vi.fn(),
    },
  };
});

vi.mock('../../lib/navigation', () => {
  return {
    navigateTo: vi.fn(),
  };
});

import { api } from '../../lib/api';
import { navigateTo } from '../../lib/navigation';

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

describe('SyncProgressPage auto-export', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    sessionStorage.clear();
  });

  it('auto-prepares Shopify content export after batch is done', async () => {
    const batchId = 'batch-123';
    const ids = ['p-1', 'p-2'];
    sessionStorage.setItem(`auto_export_shopify_content:${batchId}`, JSON.stringify({ ids }));

    const getMock = api.get as unknown as ReturnType<typeof vi.fn>;
    const postMock = api.post as unknown as ReturnType<typeof vi.fn>;

    getMock.mockImplementation(async (url: string) => {
      if (url === '/api/v1/job-batches') {
        return { data: { ok: true, data: [] } };
      }
      if (url === `/api/v1/job-batches/${batchId}`) {
        return {
          data: {
            ok: true,
            data: {
              id: batchId,
              name: 'rename_selected_product_assets',
              total_jobs: 2,
              pending_jobs: 0,
              processed_jobs: 2,
              failed_jobs: 0,
              progress_percent: 100,
              cancelled: false,
              finished_at: '2026-02-14T00:00:00Z',
              cancelled_at: null,
            },
          },
        };
      }
      if (url === `/api/v1/job-batches/${batchId}/items`) {
        return {
          data: {
            ok: true,
            data: {
              counts: { queued: 0, running: 0, succeeded: 2, failed: 0, skipped: 0 },
              running: [],
              queued: [],
              done: [],
            },
          },
        };
      }
      throw new Error(`unexpected GET ${url}`);
    });

    postMock.mockImplementation(async (url: string) => {
      if (url === '/api/v1/products/exports/shopify-content/prepare') {
        return { status: 200, data: { download_url: '/api/v1/products/exports/shopify-content/download/exp-1' } };
      }
      // Other posts (cancel/resume) are not expected in this test.
      throw new Error(`unexpected POST ${url}`);
    });

    const navMock = navigateTo as unknown as ReturnType<typeof vi.fn>;

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/sync-progress', name: 'sync-progress', component: SyncProgressPage }],
    });
    await router.push({ name: 'sync-progress', query: { batch_id: batchId, auto_export: 'shopify_content' } });
    await router.isReady();

    mount(SyncProgressPage, {
      global: {
        plugins: [router],
        stubs: { DebugLogDialog: true },
      },
    });

    // Allow initial onMounted + async loads to settle.
    await nextTick();
    await flush();
    await nextTick();

    expect(postMock).toHaveBeenCalledWith(
      '/api/v1/products/exports/shopify-content/prepare',
      { ids },
      expect.any(Object),
    );
    expect(navMock).toHaveBeenCalledWith('/api/v1/products/exports/shopify-content/download/exp-1');
  });
});


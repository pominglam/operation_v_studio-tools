import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import TcgEventsPage from '../TcgEventsPage.vue';

const apiGet = vi.fn();
const apiPost = vi.fn();

vi.mock('../../lib/api', () => ({
  api: {
    get: (...args: unknown[]) => apiGet(...args),
    post: (...args: unknown[]) => apiPost(...args),
  },
}));

describe('TcgEventsPage', () => {
  beforeEach(() => {
    apiGet.mockReset();
    apiPost.mockReset();
  });

  it('defaults start date to today and uses it for refresh', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-02-23T12:00:00'));

    apiGet.mockResolvedValue({ data: { data: [], meta: { latest_fetched_at: null } } });
    apiPost.mockResolvedValue({ data: { data: { fetched_events: 0 } } });

    const wrapper = mount(TcgEventsPage);

    await Promise.resolve();

    const input = wrapper.find('input[type=\"date\"]');
    expect((input.element as HTMLInputElement).value).toBe('2026-02-23');

    await wrapper.find('button').trigger('click');
    expect(apiPost).toHaveBeenCalledWith(
      '/api/v1/tcg/events/refresh',
      expect.objectContaining({ start_date: '2026-02-23' }),
      expect.objectContaining({ timeout: 60_000 }),
    );

    vi.useRealTimers();
  });

  it('includes hide_zero_applicants filter by default', async () => {
    apiGet.mockResolvedValue({ data: { data: [], meta: { latest_fetched_at: null } } });

    mount(TcgEventsPage);
    await Promise.resolve();

    expect(apiGet).toHaveBeenCalledWith(
      '/api/v1/tcg/events',
      expect.objectContaining({
        params: expect.objectContaining({
          hide_zero_applicants: 1,
        }),
      }),
    );
  });
});


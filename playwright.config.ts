import { defineConfig } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8020';

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: (() => {
        const raw = process.env.E2E_WORKERS;
        if (!raw) return 1;
        const n = Number.parseInt(raw, 10);
        return Number.isFinite(n) && n > 0 ? n : 1;
    })(),
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
});


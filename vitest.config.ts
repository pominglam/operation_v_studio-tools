import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['resources/js/test/setup.ts'],
    include: ['resources/js/**/*.{spec,test}.ts'],
    exclude: ['node_modules/**', 'node/aliexpress-scraper/**'],
  },
});



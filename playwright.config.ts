import { defineConfig } from '@playwright/test';

/**
 * End-to-end tests against wp-env's site (`npm run env:start` first). Its
 * port is 8888 unless .wp-env.override.json says otherwise; WP_BASE_URL
 * overrides it here.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 60_000,
    workers: 1,
    use: {
        // The installed Google Chrome, not the Chromium bundled with the pinned
        // @playwright/test, so the browser stays patched. GitHub's runners ship it.
        channel: 'chrome',
        headless: true,
        baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8888',
    },
});

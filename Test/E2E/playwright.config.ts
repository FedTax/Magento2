import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the TaxCloud Magento 2 E2E suite.
 *
 * The storefront is served by the nginx container in docker-compose.e2e.yml and
 * provisioned by `scripts/install-magento.sh` with E2E=1 (see docs/E2E_TESTS.md).
 * Bring it up with `make e2e-setup`, then `make e2e-test`.
 *
 * Env vars (all optional; see .env.example):
 *   MAGENTO_BASE_URL    storefront URL Magento was installed with (default
 *                       http://localhost:8080)
 *   PLAYWRIGHT_WORKERS  parallel workers (default 1 — see below)
 *   PLAYWRIGHT_HEADED   "true" to run headed for local debugging
 *   CI                  set by GitHub Actions; enables retries + forbids test.only
 */

const baseURL = process.env.MAGENTO_BASE_URL ?? 'http://localhost:8080';

// One worker by default: every test shares a single Magento database, so
// concurrent checkouts/orders would collide on quotes, increment IDs and the
// tax-rate cache. Override with PLAYWRIGHT_WORKERS once tests are isolated.
const workers = process.env.PLAYWRIGHT_WORKERS
  ? parseInt(process.env.PLAYWRIGHT_WORKERS, 10)
  : 1;

export default defineConfig({
  testDir: './specs',
  outputDir: './test-results',

  // Shared DB ⇒ no within-file parallelism either.
  fullyParallel: false,
  workers,

  // Fail the CI build if a test.only is committed; retry once in CI to absorb
  // the occasional first-load flake without masking real failures locally.
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,

  reporter: [['list'], ['html', { open: 'never' }]],

  use: {
    baseURL,
    headless: process.env.PLAYWRIGHT_HEADED !== 'true',
    // PLAYWRIGHT_SLOWMO=<ms> adds a delay before each action (headed only) so
    // you can watch a run. Ignored/harmless in CI where it's unset.
    launchOptions: {
      slowMo: process.env.PLAYWRIGHT_SLOWMO ? parseInt(process.env.PLAYWRIGHT_SLOWMO, 10) : 0,
    },
    // Cheap insurance for debugging CI failures — captured only when a test
    // fails, so green runs stay fast and produce no artifacts.
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },

  // Chromium only for the initial pass; Firefox/WebKit can be added later.
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});

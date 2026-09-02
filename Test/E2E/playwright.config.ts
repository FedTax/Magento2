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
  //
  // The `*-rest` projects run the SAME customer journeys a second time with
  // the store switched to the V3 REST transport (real v3 key, seeded by
  // scripts/seed-test-data.php): rest-setup flips api_type and proves the
  // switched mode authenticates, checkout-rest re-runs the checkout journeys
  // plus the credit-memo refund against v3, and rest-teardown restores SOAP
  // afterwards no matter what. Identical golden values across both passes are
  // the SOAP/REST interchangeability claim itself. (Workers default to 1, so
  // the passes never interleave.)
  projects: [
    {
      name: 'chromium',
      // specs/exemptions-on/ needs the feature switched on, which only the
      // exemptions-on project arranges. Without this they would also run here,
      // against the seeded default of OFF, and fail for the right reason at the
      // wrong time.
      //
      // specs/docs/ generates the documentation screenshots and asserts nothing;
      // it is run on demand by `make docs-screenshots`, never as part of a test
      // run.
      testIgnore: [/specs\/exemptions-on\//, /specs\/docs\//],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'rest-setup',
      testMatch: /rest-mode\.setup\.ts/,
      // Depending on chromium pins the pass order: chromium (SOAP) →
      // rest-setup → checkout-rest → rest-teardown. Without it Playwright may
      // schedule this first, and the Bearer-mode admin spec (which restores
      // SOAP in its finally) would silently undo the REST switch before the
      // checkout-rest pass runs — observed live in CI on 2026-08-07.
      dependencies: ['chromium'],
      teardown: 'rest-teardown',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'checkout-rest',
      testMatch: [
        /specs\/checkout\/.*\.spec\.ts/,
        /specs\/admin\/admin-creditmemo-triggers-refund\.spec\.ts/,
      ],
      dependencies: ['rest-setup'],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'rest-teardown',
      testMatch: /rest-mode\.teardown\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    // Exemptions switched ON. The seeded store mirrors production, where they
    // are off, so anything needing them enabled runs in this pass — with a
    // teardown PROJECT restoring the setting, which runs even when the guarded
    // tests fail. Chained after the REST pass so the two never interleave.
    {
      name: 'exemptions-on-setup',
      testMatch: /exemptions-on\.setup\.ts/,
      // After the REST pass, not merely after chromium. Depending on chromium
      // alone let this project switch exemptions on while checkout-rest was
      // still running, which broke two specs there for reasons invisible from
      // their own code: exemptions-disabled-by-default failed its precondition,
      // and logged-in-checkout lost its tax because the nominated group
      // auto-exempted the customer.
      dependencies: ['checkout-rest'],
      teardown: 'exemptions-on-teardown',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'exemptions-on',
      testMatch: /specs\/exemptions-on\/.*\.spec\.ts/,
      dependencies: ['exemptions-on-setup'],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'exemptions-on-teardown',
      testMatch: /exemptions-on\.teardown\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    // Documentation screenshots. Not part of any test run — excluded from
    // `chromium` above and depending on nothing, so `make docs-screenshots`
    // runs the trio alone against an already-installed store. Several
    // documented screens only exist with exemptions on, hence the setup and the
    // teardown that restores the seeded default of off.
    {
      name: 'docs-screenshots-setup',
      testMatch: /docs-screenshots\.setup\.ts/,
      teardown: 'docs-screenshots-teardown',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'docs-screenshots',
      testMatch: /specs\/docs\/.*\.spec\.ts/,
      dependencies: ['docs-screenshots-setup'],
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'docs-screenshots-teardown',
      testMatch: /docs-screenshots\.teardown\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});

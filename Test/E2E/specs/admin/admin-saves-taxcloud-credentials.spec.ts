import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';

/**
 * D.1 — Admin saves TaxCloud credentials.
 *
 * Proves over the integration tests: the actual Stores > Configuration > Sales >
 * Tax > TaxCloud Settings form accepts credentials and persists them (form key,
 * field handling, cache flush). Integration tests write core_config_data
 * directly; only this proves the admin form does it correctly.
 *
 * Persistence is asserted by reloading the page — the fields re-read from
 * core_config_data, so a correct reload value proves it was saved.
 *
 * IMPORTANT: this test mutates the live sandbox credentials that other tests
 * depend on, so it captures the originals up front and restores them in a
 * `finally` — even if an assertion fails.
 *
 * Note: this module stores the API ID/Key as plain text (no encryption backend
 * model), so the reloaded fields show the literal values.
 */
const TEST_API_ID = 'e2e-test-api-id';
const TEST_API_KEY = 'e2e-test-api-key-0000';

test('admin saves TaxCloud credentials and they persist', async ({ page }) => {
  test.setTimeout(120_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();
  // The V1 fields only render with SOAP selected; select it explicitly so the
  // spec doesn't depend on what an earlier test left saved.
  await config.selectApiType('soap');

  const original = await config.readCredentials();

  try {
    // Enter and save new credentials.
    await config.setCredentials(TEST_API_ID, TEST_API_KEY);
    await config.save(); // asserts "You saved the configuration."

    // Reload — the fields re-read from core_config_data, proving persistence.
    await config.open();
    const saved = await config.readCredentials();
    expect(saved.apiId).toBe(TEST_API_ID);
    expect(saved.apiKey).toBe(TEST_API_KEY);
  } finally {
    // Restore the real sandbox credentials so the rest of the suite still works.
    await config.open();
    await config.setCredentials(original.apiId, original.apiKey);
    await config.save();
  }
});

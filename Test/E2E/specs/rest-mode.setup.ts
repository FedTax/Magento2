import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Project setup for the `*-rest` projects (see playwright.config.ts): switches
 * the default scope to the V3 REST transport, so the SAME checkout/refund
 * specs that just ran over SOAP run again over v3 — identical golden values
 * are the interchangeability claim itself.
 *
 * The install is seeded with the real v3 key (TAXCLOUD_API_V3_KEY, required)
 * and the connection ID, so flipping api_type is all it takes; the Test
 * Connection assertion proves the switched mode actually authenticates with
 * the real key (X-API-KEY) before any journey runs against it.
 *
 * rest-mode.teardown.ts restores SOAP whatever happens in between.
 */
test('switch the store to V3 REST (real key)', async ({ page }) => {
  test.setTimeout(120_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  await config.selectApiType('rest');

  // Saved real key + saved connection id must authenticate.
  const result = await config.testConnection();
  expect(result).toContain('Connection successful');

  await config.save();
});

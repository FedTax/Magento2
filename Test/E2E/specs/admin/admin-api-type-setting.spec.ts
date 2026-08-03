import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';

/**
 * D.2 — API Type setting: field visibility and the Test Connection button.
 *
 * Proves over the unit tests: the real admin form's depends-wiring actually
 * flips the credential fields when the API Type select changes, and the Test
 * Connection button round-trips through the taxcloud/connection/test AJAX
 * endpoint (route, ACL, form key, JSON handling) against the live install.
 *
 * Nothing here saves the configuration — the depends mechanism reacts to the
 * form value alone, and the connection test posts form values without
 * persisting them — so there is no state to restore.
 *
 * The SOAP success-path test uses the sandbox credentials already saved on
 * the install (the same ones the checkout suites transact with), so a green
 * result here proves the full chain to TaxCloud's real Ping endpoint.
 */
test.describe('API Type setting', () => {
  test('switching API Type flips which credential fields are visible', async ({ page }) => {
    test.setTimeout(120_000);

    await new AdminLoginPage(page).login();
    const config = new TaxConfigPage(page);
    await config.open();

    await expect(config.apiType).toBeVisible();

    // V3 REST: legacy pair hidden, v3 pair + button shown.
    await config.selectApiType('rest');
    await expect(config.apiId).toBeHidden();
    await expect(config.apiKey).toBeHidden();
    await expect(config.restApiKey).toBeVisible();
    await expect(config.restConnectionId).toBeVisible();
    await expect(config.testConnectionButton).toBeVisible();

    // Back to V1 SOAP: the reverse, button still present.
    await config.selectApiType('soap');
    await expect(config.apiId).toBeVisible();
    await expect(config.apiKey).toBeVisible();
    await expect(config.restApiKey).toBeHidden();
    await expect(config.restConnectionId).toBeHidden();
    await expect(config.testConnectionButton).toBeVisible();
  });

  test('Test Connection verifies SOAP sandbox credentials and validates missing REST input', async ({ page }) => {
    test.setTimeout(120_000);

    await new AdminLoginPage(page).login();
    const config = new TaxConfigPage(page);
    await config.open();

    // V1 SOAP with the install's sandbox credentials → a real Ping success.
    await config.selectApiType('soap');
    const soapResult = await config.testConnection();
    expect(soapResult).toContain('Connection successful');

    // V3 REST with empty credential fields → the endpoint's validation
    // short-circuit answers without calling TaxCloud.
    await config.selectApiType('rest');
    await config.restApiKey.fill('');
    await config.restConnectionId.fill('');
    const restResult = await config.testConnection();
    expect(restResult).toContain('API Key');
  });
});

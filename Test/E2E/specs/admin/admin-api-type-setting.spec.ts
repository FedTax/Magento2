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

  /**
   * Bearer-mode connectivity — the basic check for the transient
   * (V1-pair-exchanged) key, and the canary for the undocumented exchange
   * endpoint. The seeded install has a REAL v3 key saved (TAXCLOUD_API_V3_KEY,
   * which every other REST test runs under), and the Test Connection button
   * falls back to SAVED credentials when the form field is blank — so to force
   * Bearer the saved key is removed first (save with the box empty), the
   * button is pressed, and the real key is restored from the environment in
   * the finally block. If this test starts failing while the X-API-KEY tests
   * stay green, suspect the exchange endpoint.
   *
   * Along the way it proves the required-entry fix in a real browser: saving
   * the section with the API Key box empty must succeed (Bearer scopes run
   * key-less).
   */
  test('Bearer mode: Test Connection succeeds with only the V1 pair', async ({ page }) => {
    test.setTimeout(180_000);

    const realV3Key = process.env.TAXCLOUD_API_V3_KEY ?? '';
    test.skip(realV3Key === '', 'TAXCLOUD_API_V3_KEY must be exported so the saved key can be restored');

    await new AdminLoginPage(page).login();
    const config = new TaxConfigPage(page);
    await config.open();

    // The V1 apiKey doubles as the v3 connection id; read it off the form.
    await config.selectApiType('soap');
    const v1ApiKey = (await config.readCredentials()).apiKey;
    expect(v1ApiKey).not.toBe('');

    try {
      // Remove the saved real key so nothing can fall back to X-API-KEY.
      await config.selectApiType('rest');
      await config.restApiKey.fill('');
      await config.restConnectionId.fill(v1ApiKey);
      await config.save();

      // Blank field + no saved key: the module must exchange the saved V1
      // credentials for a Bearer token against the LIVE exchange service and
      // ping api.v3.taxcloud.com with it.
      await config.restApiKey.fill('');
      const result = await config.testConnection();
      expect(result).toContain('Connection successful');
    } finally {
      // Restore the real v3 key and the SOAP api_type for the rest of the suite.
      await config.open();
      await config.selectApiType('rest');
      await config.restApiKey.fill(realV3Key);
      await config.save();
      await config.open();
      await config.selectApiType('soap');
      await config.save();
    }
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

    // V3 REST, no API Key (Bearer mode), and a well-formed but nonexistent
    // Connection ID → TaxCloud answers 404 and the admin is pointed at the
    // Connection ID. Unlike an empty-field assertion this is independent of
    // whatever rest_connection_id an earlier test left saved (a blank field
    // falls back to the saved value by design).
    await config.selectApiType('rest');
    await config.restApiKey.fill('');
    await config.restConnectionId.fill('00000000-dead-beef-0000-000000000000');
    const restResult = await config.testConnection();
    expect(restResult).toContain('does not know this Connection ID');
  });
});

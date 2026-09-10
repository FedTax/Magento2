import { test } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Project teardown for the `*-rest` projects: put the default scope back on
 * SOAP, the mode the seeded sandbox declares (api_type=soap), so reruns and
 * later suites find the environment as seeded regardless of how the REST
 * pass went.
 */
test('restore the store to V1 SOAP', async ({ page }) => {
  test.setTimeout(120_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();
  await config.selectApiType('soap');
  await config.save();
});

import { test } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';

/**
 * Put exemption certificates back to off — the state a real installation and
 * the seeded store both start in. Runs even when a screenshot test failed.
 */
test('restore exemptions to off', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();
  await config.setExemptions(false);
  await config.save();
});

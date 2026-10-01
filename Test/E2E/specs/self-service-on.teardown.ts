import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Put the store back the way production ships: nobody nominated, self-service
 * off, exemptions off. See resetCustomerCertificatesAndExemptions() for why it
 * takes three saves.
 */
test('restore the seeded self-service settings', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.resetCustomerCertificatesAndExemptions();

  await config.open();
  expect(await config.isExemptionsEnabled()).toBe(false);
  // Shown again only to read what is stored underneath; not saved.
  await config.setExemptions(true);
  expect(
    await config.readCustomerCertificates(),
    'the next run must start with nobody nominated, as a real install does',
  ).toEqual({ enabled: false, groups: [] });
});

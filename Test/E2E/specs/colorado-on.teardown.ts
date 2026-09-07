import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Put the store back the way production ships: Colorado Retail Delivery Fee
 * collection off. Runs even when the tests it guards fail — that is the point
 * of it being a project.
 */
test('switch Colorado Retail Delivery Fee off', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  await config.setColoradoRdf(false);
  await config.save();

  await config.open();
  expect(await config.isColoradoRdfEnabled(), 'fee collection must be back off').toBe(false);
});

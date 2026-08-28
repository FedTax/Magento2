import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';


/**
 * Put the store back the way production ships: exemptions off, no nominated
 * groups. Runs even when the tests it guards fail — that is the whole point of
 * it being a project.
 */
test('restore the seeded exemption settings', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  await config.setExemptions(false);
  await config.save();

  await config.open();
  expect(
    await config.isExemptionsEnabled(),
    'the store must be handed back matching a real install, or the next run fails on its precondition',
  ).toBe(false);
});

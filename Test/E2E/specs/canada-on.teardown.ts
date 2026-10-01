import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Put the store back the way production ships: Canadian tax off, SOAP restored.
 * Runs even when the tests it guards fail — that is the point of it being a
 * project.
 *
 * Switching the setting off also has to take the Check Canada Access button
 * with it, which is asserted here rather than in a spec of its own: proving the
 * button is gone needs the setting off, which is exactly the state this
 * teardown creates.
 */
test('switch Canadian tax off', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  // Pin REST before touching the setting. A teardown project runs even when its
  // setup was skipped — a failure anywhere earlier in the chain does that — so
  // this may find the store on SOAP, where the Canadian field is not rendered
  // at all and the stored value would silently survive.
  await config.selectApiType('rest');
  await config.setCanadaTax(false);
  await config.save();

  await config.open();
  await config.selectApiType('rest');
  expect(await config.isCanadaTaxEnabled(), 'Canadian tax must be back off').toBe(false);
  await expect(
    config.checkCanadaAccessButton,
    'the access check belongs to the setting: with Canadian tax off there is nothing to check',
  ).toBeHidden();

  // Restore the seeded transport, like the REST pass's own teardown does.
  await config.selectApiType('soap');
  await config.save();
});

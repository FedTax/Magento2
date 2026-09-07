import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Switch Colorado Retail Delivery Fee collection on, with flat rate mapped as
 * the motor-vehicle delivery method.
 *
 * A setup PROJECT paired with a teardown project, for the same reason as
 * exemptions-on: the teardown runs even when the guarded tests fail, so an
 * interrupted run cannot leave the fee switched on and surprise a
 * neighbouring spec with an extra $0.31 in its totals.
 *
 * The seeded store mirrors production, where the fee is OFF — checkout specs
 * elsewhere in the suite rely on that default.
 */
test('switch Colorado Retail Delivery Fee on', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  // Pin SOAP, like the exemptions pass: this project runs after the REST pass
  // and must not depend on which teardown restored the transport first.
  await config.selectApiType('soap');

  await config.setColoradoRdf(true, ['flatrate_flatrate']);
  await config.save();

  await config.open();
  expect(await config.isColoradoRdfEnabled(), 'fee collection must be on for this pass').toBe(true);
});

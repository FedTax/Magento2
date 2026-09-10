import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Switch exemptions on.
 *
 * A setup PROJECT rather than a beforeAll, paired with a teardown project,
 * because Playwright runs a teardown project even when the tests it guards
 * fail — an afterEach does not when the run is killed. An earlier version of
 * this coverage restored the setting in an afterEach, and one interrupted run
 * left exemptions switched on, which then failed a neighbouring spec on its
 * precondition. A test that changes global state and cannot reliably undo it
 * does not just fail, it takes its neighbours with it.
 *
 * The seeded store mirrors production, where exemptions are OFF — that default
 * is itself covered, by exemptions-disabled-by-default.
 */
test('switch exemptions on', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  // Pin the transport as well as the setting. This project becomes eligible as
  // soon as checkout-rest finishes, which is also when rest-teardown becomes
  // eligible — so the two can run in either order and this pass could otherwise
  // start while the store is still switched to REST. Pinning SOAP here makes the
  // pass deterministic instead of dependent on which teardown won the race.
  await config.selectApiType('soap');

  await config.setExemptions(true);
  await config.save();

  await config.open();
  expect(await config.isExemptionsEnabled(), 'exemptions must be on for this pass').toBe(true);
});

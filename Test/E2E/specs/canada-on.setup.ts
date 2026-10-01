import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Switch Canadian tax on, over the V3 REST transport it requires.
 *
 * A setup PROJECT paired with a teardown project, for the same reason as the
 * exemptions and Colorado passes: the teardown runs even when the guarded tests
 * fail, so an interrupted run cannot leave Canadian tax switched on. It changes
 * nothing for the US checkout specs' totals, but leaving a store in a state no
 * merchant starts in is how a later spec fails for reasons invisible in its own
 * code.
 *
 * The pass also asserts its own precondition: Canada is an add-on TaxCloud
 * enables per account, so if the seeded account does not have it, that is worth
 * one clear failure here rather than a mystified golden-value mismatch at
 * checkout.
 */
test('switch Canadian tax on (V3 REST)', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  // Canada is v3-only, and the field is hidden until REST is selected.
  await config.selectApiType('rest');
  await config.setCanadaTax(true);
  await config.save();

  await config.open();
  expect(await config.isCanadaTaxEnabled(), 'Canadian tax must be on for this pass').toBe(true);

  const access = await config.checkCanadaAccess();
  expect(
    access,
    'The seeded TaxCloud account must have Canadian tax enabled — ask TaxCloud support to enable ' +
      'it for the account in TAXCLOUD_API_ID/TAXCLOUD_API_KEY. Everything in specs/canada-on/ ' +
      'depends on it.',
  ).toContain('Canada access confirmed');
});

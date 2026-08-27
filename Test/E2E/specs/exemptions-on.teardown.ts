import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { CustomerCertificatesPage } from '../pages/admin/CustomerCertificatesPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/** The seeded customer whose attachment this pass borrows. */
const SEEDED_CUSTOMER = 2;

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

  // Two saves, in this order. The exempt-groups field is declared with a
  // <depends> on the master switch, so once exemptions are off it is hidden and
  // its stored value survives untouched — a teardown that switches off in one
  // step leaves the groups nominated, and the next suite silently auto-exempts
  // every customer in them. Observed exactly that: an integration test asserting
  // a TAXED order found the order exempt.
  await config.setExemptions(true, []);
  await config.save();

  await config.open();
  await config.setExemptions(false, []);
  await config.save();

  await config.open();
  expect(
    await config.isExemptionsEnabled(),
    'the store must be handed back matching a real install, or the next run fails on its precondition',
  ).toBe(false);
});

/**
 * Re-attach the seeded customer's certificate.
 *
 * exempt-group-auto-apply has to clear it — proving the GROUP exempts the order
 * means nothing may be attached — and every other exemption spec in the suite
 * relies on it being there. Self-healing rather than remembering a value, so an
 * interrupted run is repaired by the next one instead of poisoning it.
 */
test('re-attach the seeded customer certificate', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const panel = new CustomerCertificatesPage(page);
  await panel.openCustomer(SEEDED_CUSTOMER);
  await panel.openTab();

  const ids = await panel.certificateIds();

  if (ids.length > 0 && (await panel.attachedId()) === '') {
    await panel.attach(ids[0]);
  }

  expect(
    await panel.attachedId(),
    'the seeded customer must leave this pass with a certificate attached',
  ).not.toBe('');
});

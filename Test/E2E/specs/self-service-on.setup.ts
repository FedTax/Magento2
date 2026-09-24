import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../pages/admin/TaxConfigPage';

/**
 * Let the Wholesale group manage its own certificates.
 *
 * A setup project with its own teardown, for the reason exemptions-on.setup.ts
 * gives: this changes global state that must be put back even when the specs
 * it guards fail. It turns exemptions on itself rather than relying on the
 * exemptions pass having left them on — that pass's teardown may already have
 * run.
 *
 * SOAP is pinned for the same reason as there, and because certificates
 * created over v1 remain readable by both transports: a v3-created one makes
 * the customer's whole list unreadable over SOAP.
 */
test('let the Wholesale group manage its certificates', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  await config.selectApiType('soap');
  await config.setExemptions(true);
  await config.setCustomerCertificates(true, ['Wholesale']);
  await config.save();

  await config.open();
  expect(await config.readCustomerCertificates(), 'self-service must be on for Wholesale only').toEqual({
    enabled: true,
    groups: ['Wholesale'],
  });
});

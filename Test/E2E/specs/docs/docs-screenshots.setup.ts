import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';

/**
 * Switch exemption certificates on for the screenshot run.
 *
 * Several documented screens only exist when the feature is enabled — the
 * customer certificate panel, the My Account section, the settings fields
 * themselves. A setup PROJECT paired with a teardown project, matching
 * exemptions-on.setup.ts, so the store is put back even if a screenshot fails.
 * Leaving it on would break exemptions-disabled-by-default on the next
 * `make e2e-test`.
 */
test('enable exemptions for the screenshot run', async ({ page }) => {
  test.setTimeout(180_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();
  await config.setExemptions(true);
  await config.save();

  await config.open();
  expect(await config.isExemptionsEnabled()).toBe(true);
});

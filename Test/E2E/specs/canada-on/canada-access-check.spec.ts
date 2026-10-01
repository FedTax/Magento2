import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';

/**
 * The Check Canada Access button, in the browser.
 *
 * Canada is enabled per TaxCloud account, and a merchant has no other way to
 * find out whether theirs is. That makes this button the answer to "why is
 * there no Canadian tax?", so what matters is that it reaches TaxCloud from the
 * admin (form key, ACL, the AJAX endpoint) and renders an answer a merchant can
 * act on — none of which the PHP tests can show.
 *
 * On the base test, not the log fixture: a check against an account without
 * Canada legitimately logs nothing, but this spec is also the natural place for
 * the not-enabled path to arrive later.
 */
test('the admin can confirm Canada access from the settings page', async ({ page }) => {
  test.setTimeout(120_000);

  await new AdminLoginPage(page).login();
  const config = new TaxConfigPage(page);
  await config.open();

  const result = await config.checkCanadaAccess();

  expect(result).toContain('Canada access confirmed');
  // The sample rate is what makes the answer actionable rather than a bare OK.
  expect(result, 'the result must quote the sample rate TaxCloud calculated').toMatch(/\d+(\.\d+)?%/);
  expect(result).toContain('Toronto');
});

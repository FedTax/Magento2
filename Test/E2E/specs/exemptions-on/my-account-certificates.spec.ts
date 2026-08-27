import { test, expect } from '@playwright/test';
import { loginAsExemptCustomer } from '../../fixtures/auth';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { CustomerCertificatesPage } from '../../pages/admin/CustomerCertificatesPage';

/**
 * Exemption certificates in My Account.
 *
 * A customer managing their own certificates is the point of the feature — it
 * replaces emailing paperwork to a merchant. None of it had any coverage: the
 * page, its navigation entry, and the add/remove round trip were verified only
 * by the unit tests behind them, which cannot tell whether anything is
 * reachable.
 *
 * Runs with exemptions on, since the whole surface is hidden otherwise — that
 * hidden-by-default state is covered separately by exemptions-disabled-by-default.
 */
const MARKER = 'Selfserve';
const SEEDED_CUSTOMER = 2;

test.describe('my account certificates', () => {
  // Certificates created here are real. Clean up from the admin side, which can
  // see them regardless of what the storefront did or how far it got.
  test.afterEach(async ({ page }) => {
    test.setTimeout(180_000);
    await new AdminLoginPage(page).login();
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();
    await panel.deleteCertificatesNamed(MARKER);
  });

  test('the account menu offers the certificates page', async ({ page }) => {
    test.setTimeout(240_000);
    await loginAsExemptCustomer(page);
    await page.goto('/customer/account/');

    const items = (await page.locator('.account-nav li, .nav.items li').allInnerTexts())
      .map((t) => t.trim())
      .filter(Boolean);

    const index = items.findIndex((t) => /Tax Exemption Certificates/i.test(t));
    expect(index, `no certificates entry among: ${items.join(' | ')}`).toBeGreaterThan(-1);

    // It belongs with the other per-account settings rather than at the top:
    // the navigation sorts descending, so a high sort order put it first.
    expect(
      items.slice(0, index).some((t) => /Account Information/i.test(t)),
      'the entry must sit below Account Information, not above My Orders',
    ).toBe(true);
  });

  test('a customer sees the certificates held for them', async ({ page }) => {
    test.setTimeout(240_000);
    await loginAsExemptCustomer(page);
    await page.goto('/taxcloud/certificate/');

    await expect(page.locator('[data-taxcloud-certificates]')).toBeVisible();

    // A failed read must never render as "you have no certificates" — a
    // customer told that will create a duplicate of one they already hold.
    await expect(page.locator('[data-role="status"]')).not.toContainText(/could not/i);
    await expect(page.locator('[data-role="certificate-rows"] tr')).not.toHaveCount(0, {
      timeout: 60_000,
    });
  });

  test('a customer can remove a certificate held for them', async ({ page }) => {
    // Created from the admin panel, because creating is an administrator's act:
    // nobody verifies an exemption claim, so a shopper cannot mint one. The
    // customer can still see it and take it away.
    await new AdminLoginPage(page).login();
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();
    await panel.createCertificate(MARKER);

    await loginAsExemptCustomer(page);
    await page.goto('/taxcloud/certificate/');
    await page.waitForTimeout(6000);

    const row = page.locator('[data-role="certificate-rows"] tr').filter({ hasText: MARKER });
    await expect(row, 'the customer must see the certificate held for them').toHaveCount(1);

    page.on('dialog', (d) => d.accept());
    await row.first().locator('[data-delete]').click();
    await page.waitForTimeout(9000);

    await expect(
      page.locator('[data-role="certificate-rows"] tr').filter({ hasText: MARKER }),
      'removing it must take it away at TaxCloud, not just from the page',
    ).toHaveCount(0);
  });

  test('My Account offers no way to create a certificate', async ({ page }) => {
    await loginAsExemptCustomer(page);
    await page.goto('/taxcloud/certificate/');
    await page.waitForTimeout(6000);

    await expect(page.locator('[data-role="show-add"]')).toHaveCount(0);
    await expect(page.locator('[data-role="add-form"]')).toHaveCount(0);
  });
});

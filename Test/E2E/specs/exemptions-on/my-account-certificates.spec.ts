import { test, expect } from '@playwright/test';
import { loginAsExemptCustomer } from '../../fixtures/auth';

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

test.describe('my account certificates', () => {
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

  test('each certificate offers the customer a way to remove it', async ({ page }) => {
    test.setTimeout(240_000);

    // Deliberately does not click. Removing is irreversible at TaxCloud, and
    // proving the button works would cost an admin login, a real certificate
    // created live, and a real delete — for wiring that
    // StorefrontCertificateControllersTest already covers, including every way
    // the endpoint refuses. What only a browser can show is that the control is
    // rendered and carries the identifier the endpoint expects.
    await loginAsExemptCustomer(page);
    await page.goto('/taxcloud/certificate/');
    await page.waitForTimeout(8000);

    const rows = page.locator('[data-role="certificate-rows"] tr');
    await expect(rows).not.toHaveCount(0);

    const removable = await page.evaluate(() =>
      Array.from(document.querySelectorAll('[data-role="certificate-rows"] tr')).map((r) => {
        const control = r.querySelector('[data-delete]');
        return control ? (control.getAttribute('data-delete') || '').trim() : '';
      }));

    expect(
      removable.every((id) => id.length > 0),
      'every listed certificate must carry a remove control naming it — a control with no ' +
        'identifier silently removes nothing, and the row looks the same either way',
    ).toBe(true);
  });

  test('My Account offers no way to create a certificate', async ({ page }) => {
    await loginAsExemptCustomer(page);
    await page.goto('/taxcloud/certificate/');
    await page.waitForTimeout(6000);

    await expect(page.locator('[data-role="show-add"]')).toHaveCount(0);
    await expect(page.locator('[data-role="add-form"]')).toHaveCount(0);
  });
});

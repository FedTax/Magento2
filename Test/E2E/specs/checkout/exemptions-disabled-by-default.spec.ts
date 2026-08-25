import { test, expect } from '../../fixtures/taxcloudLog';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';
import { loginAsExemptCustomer } from '../../fixtures/auth';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage } from '../../pages/storefront/CheckoutPage';

/**
 * A.5 — the state every installation actually starts in.
 *
 * Exemption certificates are off by default, and the seeded store mirrors that
 * because it is the one configuration guaranteed to exist in production
 * everywhere. A suite that only ever ran with the feature switched on would
 * stop covering what most merchants have.
 *
 * Two things are asserted, and the second is the subtler one:
 *
 *   1. With exemptions OFF, none of the interface appears — no My Account
 *      section, no navigation link, and the page itself is not reachable.
 *
 *   2. An exemption a merchant ALREADY GRANTED still applies. Switching the
 *      surfaces off means "stop offering this to customers", not "start taxing
 *      the customers I already exempted" — the settings gate the interface, not
 *      the resolution of a certificate already attached. A store that quietly
 *      began charging tax to its exempt B2B customers because an admin turned a
 *      display setting off would be a far worse failure than an unwanted form.
 *
 * Then the feature is turned on, the interface is confirmed to appear, and the
 * setting is put back in a finally — the store is left exactly as the seed made
 * it, whatever happens in between.
 */
test('exemption surfaces are absent by default, but existing exemptions still apply', async ({ page }) => {
  test.setTimeout(240_000);

  const config = new TaxConfigPage(page);
  await new AdminLoginPage(page).login();
  await config.open();

  expect(
    await config.isExemptionsEnabled(),
    'the seeded store must mirror a real install, where exemptions are off'
  ).toBe(false);

  // ── 1. Nothing on offer ────────────────────────────────────────────────
  await loginAsExemptCustomer(page);

  await page.goto('/customer/account/');
  await expect(page.locator('.account-nav, .nav.items')).not.toContainText('Exemption Certificates');

  await page.goto('/taxcloud/certificate/');
  await expect(
    page.locator('[data-taxcloud-certificates]'),
    'the management page must not be reachable when the feature is off'
  ).toHaveCount(0);

  // ── 2. …but an exemption already granted is still honoured ─────────────
  // This customer has a certificate attached to their account. Turning the
  // customer-facing interface off must not start taxing them.
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await checkout.emptyCart();
  await product.open('test-product');
  await product.addToCart();
  await checkout.openAsCustomer();
  await checkout.selectFlatRateAndContinue({ expectTax: false });

  await expect(
    checkout.grandTotal,
    'a customer already granted an exemption must not start paying tax because a display setting changed'
  ).toHaveText('$15.00');

  // ── 3. Turned on, the interface appears ────────────────────────────────
  try {
    await new AdminLoginPage(page).login();
    await config.open();
    await config.setExemptions(true, ['1']);
    await config.save();

    await loginAsExemptCustomer(page);
    await page.goto('/taxcloud/certificate/');
    await expect(page.locator('[data-taxcloud-certificates]')).toHaveCount(1);
    await expect(page.locator('.account-nav, .nav.items')).toContainText('Exemption Certificates');
  } finally {
    // Put the store back the way the seed left it, whatever happened above.
    await new AdminLoginPage(page).login();
    await config.open();
    await config.setExemptions(false);
    await config.save();
  }
});

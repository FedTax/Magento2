import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';
import { loginAsCustomer, EXEMPT_CUSTOMER_EMAIL } from '../../fixtures/auth';
import * as path from 'path';

/**
 * Documentation screenshots that need a real order behind them: the tax line a
 * shopper sees at checkout, the order totals in the admin, and the credit memo
 * screen.
 *
 * Split from docs-screenshots.spec.ts because these place live orders through
 * the seeded store — slower, and worth failing separately from the static admin
 * screens. See that file's header for why the E2E store is the only acceptable
 * source for documentation images.
 *
 * Run with `make docs-screenshots`.
 */

const IMAGES = path.resolve(__dirname, '../../../../docs/images');

// The seeded in-state address, matching the other checkout specs, so the
// totals in the screenshots are the same ones the docs quote.
const TX_ADDRESS: GuestAddress = {
  email: 'guest@example.com',
  firstname: 'Test',
  lastname: 'Buyer',
  street: '1401 Lavaca St',
  city: 'Austin',
  region: 'Texas',
  postcode: '78701',
  telephone: '5125550100',
};

test.describe('documentation screenshots — order journey', () => {
  test.beforeEach(async ({ page }) => {
    test.setTimeout(240_000);
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  test('checkout tax line, and the resulting order in the admin', async ({ page }) => {
    const product = new ProductPage(page);
    const checkout = new CheckoutPage(page);

    await product.open('test-product');
    await product.addToCart();

    await checkout.open();
    await checkout.fillGuestShipping(TX_ADDRESS);
    await checkout.selectFlatRateAndContinue({ expectTax: true });

    // The order summary with its tax row — what a shopper actually sees.
    await expect(checkout.tax).toBeVisible({ timeout: 60_000 });
    await checkout.summary.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await checkout.summary.screenshot({
      path: path.join(IMAGES, 'testing-checkout-tax.png'),
      animations: 'disabled',
    });

    await checkout.selectCheckMoneyOrder();
    await checkout.placeOrder();
    const increment = await checkout.expectOrderPlaced();

    // Same order in the admin: the totals a merchant reconciles against their
    // TaxCloud dashboard.
    await new AdminLoginPage(page).login();
    const order = new AdminOrderPage(page);
    await order.openByIncrement(increment);

    const totals = page.locator('.order-totals, .admin__page-section-item:has-text("Order Total")').first();
    await totals.waitFor({ timeout: 30_000 });
    await totals.screenshot({
      path: path.join(IMAGES, 'testing-order-totals.png'),
      animations: 'disabled',
    });

    // Invoice it, then open the credit memo screen for the refund page.
    await order.createInvoice();
    await page.locator('button:has-text("Credit Memo")').first().click();
    const memo = page.locator('#creditmemo_item_container, .order-creditmemo-tables').first();
    await memo.waitFor({ timeout: 40_000 });
    await memo.screenshot({
      path: path.join(IMAGES, 'refund-credit-memo.png'),
      animations: 'disabled',
    });
  });

  test('exempt order record, and the customer view of certificates', async ({ page }) => {
    const product = new ProductPage(page);
    const checkout = new CheckoutPage(page);

    await loginAsCustomer(page, EXEMPT_CUSTOMER_EMAIL);

    // My Account → the customer's own certificates.
    await page.goto('/taxcloud/certificate/');
    const block = page.locator('.block-title:has-text("Tax Exemption Certificates")')
      .locator('..');
    await block.waitFor({ timeout: 40_000 });
    await page.waitForTimeout(1000);
    await block.screenshot({
      path: path.join(IMAGES, 'certificates-my-account.png'),
      animations: 'disabled',
    });

    // An order placed by that customer comes out exempt, and records why.
    await product.open('test-product');
    await product.addToCart();
    await checkout.openAsCustomer();
    await checkout.selectFlatRateAndContinue({ expectTax: false });
    await checkout.selectCheckMoneyOrder();
    await checkout.placeOrder();
    const increment = await checkout.expectOrderPlaced();

    await new AdminLoginPage(page).login();
    await new AdminOrderPage(page).openByIncrement(increment);

    const record = page.locator('.taxcloud-order-certificate').first();
    await record.waitFor({ timeout: 30_000 });
    await record.screenshot({
      path: path.join(IMAGES, 'exempt-order-record.png'),
      animations: 'disabled',
    });
  });
});

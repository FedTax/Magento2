import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';

/**
 * Colorado Retail Delivery Fee: eligible checkout and FULL refund.
 *
 * Proves over the integration tests: the storefront actually renders the fee
 * as its own totals row, the placed order carries it into the admin, and the
 * full-refund flow a CS rep drives credits it back — Total Refunded equals
 * the order Grand Total, fee included.
 *
 * Amount assertions stick to the module-priced $0.31 (config default) and to
 * relative totals; the goods' Colorado sales tax comes from the live sandbox
 * and its rates are not pinned here.
 */
const DENVER_ADDRESS: GuestAddress = {
  email: 'guest@example.com',
  firstname: 'Test',
  lastname: 'Buyer',
  street: '1600 Broadway',
  city: 'Denver',
  region: 'Colorado',
  postcode: '80202',
  telephone: '3035550100',
};

test('checkout to Colorado charges the fee and a full refund returns it', async ({ page }) => {
  test.setTimeout(300_000);

  // 1. Storefront: the fee appears as its own row, at the configured amount.
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);
  await product.open('test-product');
  await product.addToCart();
  await checkout.open();
  await checkout.fillGuestShipping(DENVER_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  await expect(checkout.rdfFee.first()).toContainText('$0.31', { timeout: 60_000 });

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  const orderNo = await checkout.orderNumber();
  expect(orderNo).toMatch(/^\d+$/);

  // 2. Admin: the order view shows the fee row, and the grand total holds it.
  await new AdminLoginPage(page).login();
  const order = new AdminOrderPage(page);
  await order.openByIncrement(orderNo);
  expect(await order.totalsRowAmount('Colorado Retail Delivery Fee')).toBe('$0.31');

  await order.createInvoice();
  // Grace period between capture and refund — the sandbox records captures
  // asynchronously (see admin-creditmemo-triggers-refund.spec.ts).
  await page.waitForTimeout(15_000);

  const grandTotal = await order.grandTotal();

  // 3. Full refund: the credit memo carries the fee and refunds everything.
  await order.refundOffline();
  expect(await order.status()).toBe('Closed');
  expect(
    await order.totalRefunded(),
    'a full return must refund the whole order, Colorado Retail Delivery Fee included'
  ).toBe(grandTotal);
});

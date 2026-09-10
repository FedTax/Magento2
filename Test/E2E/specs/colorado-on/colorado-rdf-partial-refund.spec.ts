import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';

/**
 * Colorado Retail Delivery Fee: PARTIAL refund keeps the fee.
 *
 * The fee attaches to the delivery, not the items: refunding one of two units
 * (delivery happened, one unit kept) must not credit the $0.31 back. The
 * assertion is on the amounts the admin sees — the difference between the
 * grand total and the refunded total still contains the kept unit AND the
 * fee.
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

const dollars = (s: string): number => parseFloat(s.replace(/[^0-9.]/g, ''));

test('a partial refund does not return the fee', async ({ page }) => {
  test.setTimeout(300_000);

  // 1. Two units to Denver; fee charged once, not per item.
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);
  await product.open('test-product');
  await product.setQty(2);
  await product.addToCart();
  await checkout.open();
  await checkout.fillGuestShipping(DENVER_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  await expect(checkout.rdfFee.first()).toContainText('$0.31', { timeout: 60_000 });

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  const orderNo = await checkout.orderNumber();
  expect(orderNo).toMatch(/^\d+$/);

  // 2. Invoice, then refund ONE of the two units.
  await new AdminLoginPage(page).login();
  const order = new AdminOrderPage(page);
  await order.openByIncrement(orderNo);
  await order.createInvoice();
  await page.waitForTimeout(15_000);

  const grandTotal = dollars(await order.grandTotal());

  await order.refundOfflinePartial(1);

  // 3. The order stays open and the fee stays charged: what was NOT refunded
  // is at least the kept unit ($10) plus the fee.
  expect(await order.status()).toBe('Processing');
  const refunded = dollars(await order.totalRefunded());
  expect(
    grandTotal - refunded,
    'the unrefunded remainder must still contain the kept unit and the $0.31 fee'
  ).toBeGreaterThanOrEqual(10.31);
});

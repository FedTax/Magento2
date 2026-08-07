import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';

/**
 * A.3 — Configurable product checkout + refund, under the gateway-error
 * watcher.
 *
 * Composite products travel through the module differently from simples:
 * credit memos carry BOTH the configurable parent row and its child row under
 * the child's SKU, which broke v3 REST refunds live on 2026-08-07 (duplicate
 * itemId rejected) while every suite stayed green — the module never blocks
 * the admin flow on a gateway failure, so the journey completed and only
 * var/log/taxcloud.log knew. This spec makes that class of failure red: the
 * whole journey (lookup at checkout, capture at placement, refund at credit
 * memo) runs with a real configurable, and the taxcloudLog fixture fails the
 * test on any new gateway ERROR line. Runs in both transport passes
 * (chromium = SOAP, checkout-rest = v3 REST).
 *
 * Totals are asserted structurally (tax present, sum consistent) rather than
 * as golden values — the golden-value coverage lives in A.1; this spec's job
 * is the composite shape and the error watcher.
 */
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

const money = (s: string): number => parseFloat(s.replace(/[^0-9.]/g, ''));

test('configurable variant checks out with tax and refunds cleanly', async ({ page }) => {
  // Live TaxCloud calls at totals, placement and refund + several admin pages.
  test.setTimeout(240_000);

  // 1. Storefront: buy the BLUE variant of the seeded configurable. Blue
  // carries TIC 00000 (general, standard-rated) so a tax row renders; the RED
  // sibling's TIC 20010 rates to zero on the sandbox account, which would
  // leave no tax row for the checkout page object to find. (Variant-TIC
  // resolution itself is covered by the integration suite.)
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);
  await product.open('test-configurable');
  await product.selectConfigurableOption('Blue');
  await product.addToCart();

  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  // Shipped in-state with a standard-rated TIC: a tax row must be present and
  // the totals must be internally consistent.
  const sub = money(await checkout.subtotal.innerText());
  const ship = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());
  expect(tax).toBeGreaterThan(0);
  expect(grand).toBeCloseTo(sub + ship + tax, 2);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  const orderNo = await checkout.orderNumber();
  expect(orderNo).toMatch(/^\d+$/);

  // 2. Admin: invoice, then refund via credit memo — the flow that produces
  // the parent+child duplicate-SKU rows.
  await new AdminLoginPage(page).login();
  const order = new AdminOrderPage(page);
  await order.openByIncrement(orderNo);
  await order.createInvoice();
  // TaxCloud records captures asynchronously: a Returned/refund fired within
  // seconds of the capture can answer "order ... has not been captured yet"
  // (observed live in CI 2026-08-07 — previously masked by orderId collisions
  // with long-captured stale orders). Give the sandbox a grace period between
  // the capture (order placement) and the refund.
  await page.waitForTimeout(15_000);
  await order.refundOffline();

  await expect(page.locator('.message-success').first()).toContainText('created the credit memo');
  expect(await order.status()).toBe('Closed');

  // 3. The gatewayErrorWatcher fixture now asserts no tclogger.ERROR line was
  // written by any of the lookup/capture/refund calls this journey made.
});

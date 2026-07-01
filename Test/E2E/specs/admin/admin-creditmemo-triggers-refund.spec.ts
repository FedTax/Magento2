import { test, expect } from '@playwright/test';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';

/**
 * B.1 — Admin credit memo triggers the refund path.
 *
 * Proves over the integration tests: the actual admin UI a CS rep uses (open
 * order → Invoice → Credit Memo → Refund Offline) drives the module's refund
 * (Returned) path. Integration tests call CreditmemoService::refund() directly;
 * only this proves the admin form gets the same outcome.
 *
 * UI-only assertions (no SOAP call inspection, per project decision): the admin
 * success message and the resulting order state. The real `Returned` SOAP call
 * fires against the live sandbox as part of the flow.
 *
 * The order is created at runtime via the storefront checkout (reusing A.1's
 * page objects), so no seeded order fixture is needed.
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

test('admin credit memo refunds the order', async ({ page }) => {
  // Storefront order placement (live SOAP) + several admin page loads.
  test.setTimeout(240_000);

  // 1. Place an order to refund.
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);
  await product.open('test-product');
  await product.addToCart();
  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();
  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  const orderNo = await checkout.orderNumber();
  expect(orderNo).toMatch(/^\d+$/);

  // 2. In admin, open it, invoice it, then refund via credit memo.
  await new AdminLoginPage(page).login();
  const order = new AdminOrderPage(page);
  await order.openByIncrement(orderNo);
  await order.createInvoice();
  await order.refundOffline();

  // 3. The admin confirms the credit memo, and a full refund closes the order...
  await expect(page.locator('.message-success').first()).toContainText('created the credit memo');
  expect(await order.status()).toBe('Closed');
  // ...refunding the whole order amount: product $10 + shipping $5 + tax $1.24.
  expect(await order.totalRefunded()).toBe('$16.24');
});

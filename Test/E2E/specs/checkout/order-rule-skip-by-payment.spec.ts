import { test, expect } from '../../fixtures/taxcloudLog';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';
import { OrderRulesPage } from '../../pages/admin/OrderRulesPage';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * A "Skip TaxCloud" order rule on a payment method, as the storefront
 * customer and the merchant see it.
 *
 * Proves over the integration suite: the storefront checkout re-collects
 * totals when the payment method is chosen (Magento's SalesRule mixin saves
 * it and reloads totals), so the skip takes effect in the browser — the tax
 * TaxCloud showed at the shipping step is replaced by the store's own tax
 * rules, which this install does not have, so no tax is charged. The placed
 * order carries the explanatory comment in the admin.
 *
 * The rule is deleted in `finally`: left behind, it would untax every later
 * checkout in the run.
 */
const RULE = 'E2E skip check orders';

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

test('a skip rule on the payment method takes the order away from TaxCloud', async ({ page }) => {
  test.setTimeout(240_000);

  await new AdminLoginPage(page).login();
  const rules = new OrderRulesPage(page);
  // A retried attempt starts from what the failed one left behind.
  await rules.delete(RULE);

  try {
    await rules.create({ name: RULE, action: 'skip', paymentMethods: ['checkmo'] });

    const product = new ProductPage(page);
    const checkout = new CheckoutPage(page);
    await product.open('test-product');
    await product.addToCart();
    await checkout.open();
    await checkout.fillGuestShipping(TX_ADDRESS);
    // Tax may already be gone by the time the payment step renders: Luma
    // pre-selects the only payment method, which saves it and reloads totals.
    await checkout.selectFlatRateAndContinue({ expectTax: false });
    await checkout.selectCheckMoneyOrder();

    // No native tax rules are seeded, so a skipped quote is untaxed.
    await expect.poll(() => checkout.hasTaxRow(), { timeout: 60_000 }).toBe(false);
    await expect(checkout.grandTotal).toHaveText('$15.00', { timeout: 60_000 });

    await checkout.placeOrder();
    const orderNumber = await checkout.expectOrderPlaced();

    const order = new AdminOrderPage(page);
    await order.openByIncrement(orderNumber);
    const history = page.locator('#order_history_block, .order-history-comments, .note-list').first();
    await expect(history).toContainText('TaxCloud was not used for this order', { timeout: 30_000 });
    await expect(history).toContainText(`Order rule "${RULE}" matched payment method`);
  } finally {
    await rules.delete(RULE);
  }
});

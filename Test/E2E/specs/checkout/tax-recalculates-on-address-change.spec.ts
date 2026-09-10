import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * A.2 — Tax is destination-specific in the checkout UI.
 *
 * Proves over the integration tests: the customer-facing checkout shows the
 * correct, live-calculated tax for the destination, and a different destination
 * produces a different tax in the rendered totals — exercising the JS + AJAX +
 * server round-trip that integration tests can't.
 *
 * Why two checkouts instead of editing the address mid-flow: Luma only renders
 * the Tax row on the payment step, and going back to re-open the saved address
 * form is unreliable. Two clean guest checkouts (TX, then CA) are robust and
 * still prove the claim — different destination state => different tax shown.
 *
 * REAL SERVICES, golden values (see A.1). Nexus is configured for TX and CA in
 * the TaxCloud sandbox; NY is intentionally NOT used (no nexus => no tax row).
 */
const EXPECTED_TX_TAX = '$1.24'; // Austin TX: 8.25% on the taxed $15.00 (TX taxes shipping)
const EXPECTED_CA_TAX = '$0.99'; // South San Francisco CA: 9.875% on the $10 product only (CA does not tax shipping)

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

const CA_ADDRESS: GuestAddress = {
  email: 'guest@example.com',
  firstname: 'Test',
  lastname: 'Buyer',
  street: '864 Alta Loma Dr',
  city: 'South San Francisco',
  region: 'California',
  postcode: '94080',
  telephone: '5125550100',
};

test('tax differs by destination state, with no uncaught JS errors', async ({ page }) => {
  // Two full checkouts, each making live TaxCloud calls.
  test.setTimeout(240_000);

  // "No JS errors" = no uncaught exceptions during the checkout AJAX flow.
  const pageErrors: string[] = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  // Pass 1 — ship to Texas, then place the order (clears the cart for pass 2).
  await product.open('test-product');
  await product.addToCart();
  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();
  const txTax = await checkout.taxAmount();
  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();

  // Pass 2 — fresh checkout shipping to California.
  await product.open('test-product');
  await product.addToCart();
  await checkout.open();
  await checkout.fillGuestShipping(CA_ADDRESS);
  await checkout.selectFlatRateAndContinue();
  const caTax = await checkout.taxAmount();

  // The customer sees the right tax for each state...
  expect(txTax).toBe(EXPECTED_TX_TAX);
  expect(caTax).toBe(EXPECTED_CA_TAX);
  // ...and changing the destination state changes the tax.
  expect(caTax).not.toBe(txTax);
  // ...with no uncaught JS exceptions during the flow.
  expect(pageErrors, `uncaught JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});

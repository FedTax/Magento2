import { test, expect } from '@playwright/test';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * A.1 — Storefront checkout with tax (the headline journey).
 *
 * Proves over the integration tests: the customer actually SEES the right tax in
 * the checkout UI, the JS totals block updates, the place-order button works, and
 * the confirmation page renders. Integration tests prove the DB totals; only this
 * proves what the customer sees.
 *
 * REAL SERVICES, no SOAP mock. The values below are the live TaxCloud sandbox
 * response for the fixed seed data (Test Product $10 + Flat Rate $5) shipped to
 * the seeded in-state Austin TX address. They are intentionally pinned as a
 * golden value: if this test goes red, either TaxCloud changed the rate for this
 * address (investigate, then update the constant) or the sandbox is down.
 *   Tax = 8.25% on the taxed $15.00 = $1.24.
 */
const EXPECTED_SUBTOTAL = '$10.00';
const EXPECTED_SHIPPING = '$5.00';
const EXPECTED_TAX = '$1.24';
const EXPECTED_GRAND_TOTAL = '$16.24';

// Matches the seeded ship-from origin (in-state), so tax is deterministic.
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

test('guest sees the right tax and completes checkout', async ({ page }) => {
  // The full journey makes several live TaxCloud SOAP calls (lookup on totals,
  // verifyAddress, authorizedWithCapture on place), so give it a real budget.
  test.setTimeout(150_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-product');
  await product.addToCart();

  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  // The customer sees the right tax + totals in the checkout summary (golden).
  await expect(checkout.subtotal).toHaveText(EXPECTED_SUBTOTAL);
  await expect(checkout.shipping).toHaveText(EXPECTED_SHIPPING);
  await expect(checkout.tax).toHaveText(EXPECTED_TAX);
  await expect(checkout.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);

  // Internal invariant on the live values: grand total = subtotal + shipping + tax.
  const sub = money(await checkout.subtotal.innerText());
  const ship = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());
  expect(grand).toBeCloseTo(sub + ship + tax, 2);

  // The order completes and the confirmation shows an order number.
  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order # is:');
  expect(await checkout.orderNumber()).toMatch(/^\d+$/);
});

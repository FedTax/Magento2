import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * A Canadian customer sees Canadian tax in the checkout UI and can complete the
 * order.
 *
 * The integration suite proves the quote totals and the filed payload; only
 * this proves the journey a Canadian shopper actually takes — the country
 * dropdown, the province list that replaces the state list, a postal code where
 * Luma expects a ZIP, and the Tax row updating from a server-side v3 lookup.
 *
 * REAL SERVICES, no mock: the values are the live TaxCloud answer for the
 * seeded cart (Test Product $10 + Flat Rate $5) shipped to Toronto, Ontario,
 * where HST is 13% on general goods. Pinned as a golden value: if this goes
 * red, either Ontario's rate moved (investigate, then update the constant) or
 * Canada is no longer enabled on the account — canada-on.setup.ts checks that
 * second case first and says so.
 *   Tax = 13% of the taxed $15.00 = $1.95.
 */
const EXPECTED_SUBTOTAL = '$10.00';
const EXPECTED_SHIPPING = '$5.00';
const EXPECTED_TAX = '$1.95';
const EXPECTED_GRAND_TOTAL = '$16.95';

/** Toronto City Hall. Lower case and unspaced, as a shopper would type it. */
const ONTARIO_ADDRESS: GuestAddress = {
  email: 'guest@example.com',
  firstname: 'Test',
  lastname: 'Buyer',
  street: '100 Queen St W',
  city: 'Toronto',
  country: 'Canada',
  region: 'Ontario',
  postcode: 'm5h2n2',
  telephone: '4165550100',
};

const money = (s: string): number => parseFloat(s.replace(/[^0-9.]/g, ''));

test('guest sees Canadian tax and completes checkout to Ontario', async ({ page }) => {
  // A full journey with live v3 calls (lookup on every totals recompute, order
  // on place) — same budget as the US journeys.
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-product');
  await product.addToCart();

  await checkout.open();
  await checkout.fillGuestShipping(ONTARIO_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  await expect(checkout.subtotal).toHaveText(EXPECTED_SUBTOTAL);
  await expect(checkout.shipping).toHaveText(EXPECTED_SHIPPING);
  await expect(checkout.tax).toHaveText(EXPECTED_TAX);
  await expect(checkout.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);

  // Internal invariant on the live values, independent of the goldens above.
  expect(money(await checkout.grandTotal.innerText())).toBeCloseTo(
    money(EXPECTED_SUBTOTAL) + money(EXPECTED_SHIPPING) + money(await checkout.taxAmount()),
    2,
  );

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order');
});

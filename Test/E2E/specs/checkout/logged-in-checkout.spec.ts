import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage } from '../../pages/storefront/CheckoutPage';
import { loginAsPlainCustomer } from '../../fixtures/auth';

/**
 * A.2 — Logged-in checkout with tax.
 *
 * The suite's first signed-in customer journey; everything before it checked out
 * as a guest. That gap mattered on its own — a logged-in cart resolves the
 * customer and their saved address rather than a typed-in one, and the module
 * looks up tax against that customer — and it also blocked exemption coverage
 * entirely, since a certificate only applies to a customer with an account.
 *
 * This is the CONTROL for exempt-customer-checkout.spec.ts: same product, same
 * shipping method, same destination, differing only in who is signed in. Read
 * the two together — on its own, neither can tell "the exemption applied" apart
 * from "tax is broken".
 *
 * Golden values match the guest journey (A.1): the seeded customer's default
 * address is the same in-state Austin TX address, so the live sandbox returns
 * the same 8.25% on the taxed $15.00.
 */
const EXPECTED_SUBTOTAL = '$10.00';
const EXPECTED_SHIPPING = '$5.00';
const EXPECTED_TAX = '$1.24';
const EXPECTED_GRAND_TOTAL = '$16.24';

const money = (s: string): number => parseFloat(s.replace(/[^0-9.]/g, ''));

test('signed-in customer sees the right tax and completes checkout', async ({ page }) => {
  // Live TaxCloud calls on totals and on place-order, plus a login round-trip.
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await loginAsPlainCustomer(page);

  await product.open('test-product');
  await product.addToCart();

  // No address to fill: the seeded account's default Austin TX address is
  // already selected, which is the point of the signed-in flow.
  await checkout.openAsCustomer();
  await checkout.selectFlatRateAndContinue();

  await expect(checkout.subtotal).toHaveText(EXPECTED_SUBTOTAL);
  await expect(checkout.shipping).toHaveText(EXPECTED_SHIPPING);
  await expect(checkout.tax).toHaveText(EXPECTED_TAX);
  await expect(checkout.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);

  const sub = money(await checkout.subtotal.innerText());
  const ship = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());
  expect(grand).toBeCloseTo(sub + ship + tax, 2);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await checkout.expectOrderPlaced();
});

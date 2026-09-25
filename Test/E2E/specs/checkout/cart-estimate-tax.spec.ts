import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CartPage } from '../../pages/storefront/CartPage';

/**
 * A.6 — The cart page's "Estimate Shipping and Tax" box shows tax.
 *
 * The estimator collects only a state and ZIP, which TaxCloud refuses as an
 * address; the extension sends it with a placeholder street and city and
 * TaxCloud prices it by ZIP. Only this proves the customer SEES that estimate:
 * the partial address posted by Luma's JS, the totals round-trip, and the Tax
 * row rendered in the cart. The taxcloudLog fixture also fails the test if the
 * lookup logged an error (e.g. TaxCloud rejecting the placeholder address).
 *
 * Runs in both the SOAP pass and the REST pass (specs/checkout/).
 *
 * REAL SERVICES, golden value. 78701 (Austin TX) lies in one set of
 * jurisdictions, so the ZIP-level estimate equals the checkout tax pinned in
 * A.1: 8.25% on the taxed $15.00 (Test Product $10 + Flat Rate $5) = $1.24.
 */
const EXPECTED_ESTIMATED_TAX = '$1.24';
const EXPECTED_GRAND_TOTAL = '$16.24';

test('guest sees estimated tax on the cart page from state and ZIP alone', async ({ page }) => {
  // Each estimator change is a server round-trip with a live TaxCloud lookup.
  test.setTimeout(120_000);

  const product = new ProductPage(page);
  const cart = new CartPage(page);

  await product.open('test-product');
  await product.addToCart();

  await cart.open();
  // Before any destination there is nothing to tax.
  expect(await cart.hasTaxRow()).toBe(false);

  await cart.estimate('Texas', '78701');

  await expect(cart.tax).toHaveText(EXPECTED_ESTIMATED_TAX, { timeout: 60_000 });
  await expect(cart.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);
});

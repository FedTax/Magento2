import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage } from '../../pages/storefront/CheckoutPage';
import { loginAsExemptCustomer } from '../../fixtures/auth';

/**
 * A.4 — an exemption certificate does not exempt everywhere.
 *
 * The same customer as exempt-customer-checkout.spec.ts, whose certificate
 * covers TEXAS, shipping to CALIFORNIA instead. They must be taxed.
 *
 * This is the case neither other spec can reach. "Exempt customer pays no tax"
 * and "ordinary customer pays tax" both pass if the module simply applied every
 * certificate it found, ignoring which states it covers — which is close to the
 * defect that shipped before: covered states parsed wrongly, so coverage was
 * never really consulted. That bug made every certificate cover nothing; the
 * mirror-image bug would make every certificate cover everything, and only this
 * spec would notice.
 *
 * California is chosen because the sandbox account has CA nexus, so the
 * destination is genuinely taxable and $0.99 is a real charge rather than the
 * absence of one. A no-nexus state would report zero whether coverage was
 * checked or not.
 */
const EXPECTED_SUBTOTAL = '$10.00';
const EXPECTED_SHIPPING = '$5.00';
/** South San Francisco CA: 9.875% on the $10 product — CA does not tax shipping. */
const EXPECTED_TAX = '$0.99';
const EXPECTED_GRAND_TOTAL = '$15.99';

const CA_ADDRESS = {
  firstname: 'Exempt',
  lastname: 'Customer',
  street: '864 Alta Loma Dr',
  city: 'South San Francisco',
  region: 'California',
  postcode: '94080',
  telephone: '5125550100',
};

test('exempt customer is taxed where their certificate does not apply', async ({ page }) => {
  test.setTimeout(240_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await loginAsExemptCustomer(page);
  // A signed-in customer's cart persists between runs; start from empty or the
  // golden totals below are meaningless.
  await checkout.emptyCart();

  await product.open('test-product');
  await product.addToCart();

  await checkout.openAsCustomer();
  // Their saved address is in TX, which their certificate does cover — ship
  // somewhere it does not.
  await checkout.addNewShippingAddress(CA_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  await expect(checkout.subtotal).toHaveText(EXPECTED_SUBTOTAL);
  await expect(checkout.shipping).toHaveText(EXPECTED_SHIPPING);

  // The point of the spec: holding a certificate is not enough. It has to
  // cover where the order is going.
  await expect(checkout.tax).toHaveText(EXPECTED_TAX);
  await expect(checkout.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await checkout.expectOrderPlaced();
});

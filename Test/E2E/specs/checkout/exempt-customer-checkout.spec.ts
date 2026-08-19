import { test, expect } from '../../fixtures/taxcloudLog';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage } from '../../pages/storefront/CheckoutPage';
import { loginAsExemptCustomer } from '../../fixtures/auth';

/**
 * A.3 — Exempt customer checks out tax-free.
 *
 * The end of the thread this change exists for: a customer holding a TaxCloud
 * exemption certificate that covers the destination state is charged no tax.
 *
 * Read alongside logged-in-checkout.spec.ts, which runs the SAME product to the
 * SAME address as a plain customer and asserts $1.24. Only the pair is
 * conclusive — a zero tax line here could otherwise mean the exemption worked
 * or that tax collection is broken, and the control tells them apart.
 *
 * Why TX: the sandbox account has TX nexus, so this destination is genuinely
 * taxable and the certificate has something to suppress. A no-nexus state would
 * report zero whether or not exemptions work at all.
 *
 * Certificate provenance matters here. seed-test-data.php creates it over V1
 * SOAP on purpose, because v3 reads certificates from both APIs while v1 cannot
 * read a v3-created one — so a single v1 certificate is what lets this spec run
 * under BOTH the `chromium` (SOAP) and `checkout-rest` (V3 REST) projects. If
 * the seed is ever switched to create over v3, the SOAP pass of this spec is
 * what will go red.
 */
const EXPECTED_SUBTOTAL = '$10.00';
const EXPECTED_SHIPPING = '$5.00';
/** No tax line: the certificate covers TX, so the taxed $15.00 yields $0.00. */
const EXPECTED_GRAND_TOTAL = '$15.00';

test('exempt customer is charged no tax and completes checkout', async ({ page }) => {
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await loginAsExemptCustomer(page);

  await product.open('test-product');
  await product.addToCart();

  await checkout.openAsCustomer();
  // expectTax: false — Luma omits the Tax row entirely at zero rather than
  // rendering "$0.00", so waiting for it would time out on a passing run.
  await checkout.selectFlatRateAndContinue({ expectTax: false });

  await expect(checkout.subtotal).toHaveText(EXPECTED_SUBTOTAL);
  await expect(checkout.shipping).toHaveText(EXPECTED_SHIPPING);

  // The exemption itself: no tax row, or a row reading zero if a future Luma
  // renders one. Both are "no tax charged"; anything else is a real failure.
  if (await checkout.hasTaxRow()) {
    await expect(checkout.tax).toHaveText(/\$0\.00/);
  }

  // Grand total = subtotal + shipping, with nothing added for tax. This is the
  // assertion that would fail if the certificate silently stopped applying,
  // even were the tax row hidden for some unrelated reason.
  await expect(checkout.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await checkout.expectOrderPlaced();
});

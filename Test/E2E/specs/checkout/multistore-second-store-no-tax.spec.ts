import { test, expect } from '@playwright/test';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * A.2 — Multi-store: the second store view honors its TaxCloud override (TC-1
 * at browser level).
 *
 * The seeded install is a real multi-store setup: TaxCloud enabled at default
 * scope, DISABLED at the second store view, with store codes in URLs so both
 * stores share one base URL (/default/..., /second/...). This spec drives the
 * same guest checkout as A.1 but on /second/ and proves what the customer sees:
 * NO TaxCloud tax anywhere in checkout, and the order still completes.
 *
 * What only this spec covers (vs the integration suite): store resolution from
 * the URL store code through real HTTP — integration tests set the quote's
 * store programmatically and never exercise Magento's store-from-URL routing.
 *
 * The default-store contrast (tax present, $1.24) is pinned by A.1
 * (storefront-checkout-with-tax.spec.ts); together they prove the same catalog
 * taxes differently per store view.
 */
const SECOND_STORE = 'second';

const EXPECTED_SUBTOTAL = '$10.00';
const EXPECTED_SHIPPING = '$5.00';
// No TaxCloud on this store and no native tax rules seeded -> total is untaxed.
const EXPECTED_GRAND_TOTAL = '$15.00';

// Same in-state address as A.1 — on the default store this exact cart carries
// $1.24 tax, which is what makes the zero here meaningful.
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

test('second store view charges no TaxCloud tax and checkout completes', async ({ page }) => {
  // No TaxCloud round-trips are expected on this store, but the checkout is
  // still a full server-rendered journey — keep a generous budget.
  test.setTimeout(150_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-product', SECOND_STORE);
  // Sanity: we really are on the second store (store code in every URL).
  await expect(page).toHaveURL(new RegExp(`/${SECOND_STORE}/`));
  await product.addToCart();

  await checkout.open(SECOND_STORE);
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue({ expectTax: false });

  // The customer sees untaxed totals. Luma hides the Tax row entirely at zero
  // tax; tolerate an explicitly rendered $0.00 as equivalent.
  await expect(checkout.subtotal).toHaveText(EXPECTED_SUBTOTAL);
  await expect(checkout.shipping).toHaveText(EXPECTED_SHIPPING);
  if (await checkout.tax.count()) {
    await expect(checkout.tax.first()).toHaveText('$0.00');
  }
  await expect(checkout.grandTotal).toHaveText(EXPECTED_GRAND_TOTAL);

  // The order completes on the second store — the multi-store scope tree
  // (sequence tables, stock assignment, payment/shipping config) is intact.
  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order # is:');
  expect(await checkout.orderNumber()).toMatch(/^\d+$/);
});

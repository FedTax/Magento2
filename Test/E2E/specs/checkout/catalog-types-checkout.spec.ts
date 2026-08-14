import { test, expect } from '@playwright/test';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * A.4 — What the customer is charged for a composite cart.
 *
 * The integration suite proves what reaches TaxCloud and what lands in the
 * database. This proves the last mile: the number in the checkout summary, for
 * the cart shapes where that number was wrong.
 *
 * Deliberately thin. A per-type matrix belongs in the integration suite, which
 * runs in seconds against a mocked SOAP client; every scenario here makes live
 * TaxCloud calls through a real browser, so it earns its place only by covering
 * something the layers below cannot see.
 *
 * REAL SERVICES, no SOAP mock — same contract as the other specs in this folder.
 * Rather than pin a golden total (which a rate change would break, and which
 * per-line cent rounding makes fiddly to predict on a multi-line cart), these
 * assert the RATE against the taxed base. That is what the bundle defect broke:
 * the customer was charged a third of the correct tax, which no tolerance hides.
 */

// In-state with the seeded Austin TX origin, so the rate is the local one.
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

/** Austin TX combined rate for the seeded origin/destination. */
const RATE = 0.0825;

/** Per-line cent rounding means the total can drift a few cents from rate x base. */
const CENTS_TOLERANCE = 0.05;

const money = (s: string): number => parseFloat(s.replace(/[^0-9.]/g, ''));

/**
 * The whole point of the bundle fix, seen from the customer's side.
 *
 * The seeded dynamic bundle is $30 of selections (1 x $10 + 2 x $10). Three of
 * them is $90 of goods. Before the fix TaxCloud was told about one unit of each
 * selection, so the customer was billed tax on $30 and the store quietly ate the
 * difference — a shortfall far larger than any rounding tolerance.
 */
test('a multi-quantity dynamic bundle is taxed on the whole cart', async ({ page }) => {
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-bundle-dynamic');
  await product.addBundleToCart(3);

  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  const subtotal = money(await checkout.subtotal.innerText());
  const shipping = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());

  // Three bundles of $30 of selections.
  expect(subtotal).toBeCloseTo(90, 2);

  // The assertion that fails loudly on the regression: a third of this is 2.48.
  expect(tax).toBeCloseTo((subtotal + shipping) * RATE, 1);
  expect(tax).toBeGreaterThan((subtotal + shipping) * RATE - CENTS_TOLERANCE);

  expect(grand).toBeCloseTo(subtotal + shipping + tax, 2);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order # is:');
});

/**
 * Several catalog types in one cart, which is how a real order arrives.
 *
 * Each type contributes its lines through different code, and a cart mixing them
 * is the case where one type's handling can quietly corrupt another's total.
 */
test('a cart mixing catalog types is taxed on every line', async ({ page }) => {
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-product');
  await product.addToCart();

  await product.open('test-bundle-fixed');
  await product.addBundleToCart();

  await product.open('test-downloadable');
  await product.addToCart();

  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  const subtotal = money(await checkout.subtotal.innerText());
  const shipping = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());

  // $10 simple + $50 fixed bundle + $10 downloadable.
  expect(subtotal).toBeCloseTo(70, 2);

  expect(tax).toBeCloseTo((subtotal + shipping) * RATE, 1);
  expect(grand).toBeCloseTo(subtotal + shipping + tax, 2);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order # is:');
});

/**
 * A configurable is taxed on the variant the shopper chose, not on the parent.
 *
 * The seeded Red variant carries its own TIC (20010) while the parent carries
 * none, so this also exercises the TIC travelling from the chosen simple —
 * ConfigurableProductVariantTicTest asserts that in the payload, and this
 * asserts the shopper is charged accordingly.
 */
test('a configurable is taxed on the chosen variant', async ({ page }) => {
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-configurable');
  await product.selectVariant('Red');
  await product.setQty(2);
  await product.addToCart();

  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  const subtotal = money(await checkout.subtotal.innerText());
  const shipping = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());

  expect(subtotal).toBeCloseTo(20, 2);
  expect(tax).toBeCloseTo((subtotal + shipping) * RATE, 1);
  expect(grand).toBeCloseTo(subtotal + shipping + tax, 2);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order # is:');
});

/**
 * A grouped product is not one line but several.
 *
 * Magento never puts the grouped product itself in the cart: each association
 * becomes an independent line at its own quantity. Two associations at
 * different quantities is the case that would expose any attempt to treat the
 * group as a composite with a shared parent.
 */
test('a grouped product is taxed as its associated lines', async ({ page }) => {
  test.setTimeout(180_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-grouped');
  // 2 x Test Product ($10) + 1 x Test Virtual ($10).
  await product.setGroupedQuantities([2, 1]);
  await product.addToCart();

  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();

  const subtotal = money(await checkout.subtotal.innerText());
  const shipping = money(await checkout.shipping.innerText());
  const tax = money(await checkout.tax.innerText());
  const grand = money(await checkout.grandTotal.innerText());

  expect(subtotal).toBeCloseTo(30, 2);
  expect(tax).toBeCloseTo((subtotal + shipping) * RATE, 1);
  expect(grand).toBeCloseTo(subtotal + shipping + tax, 2);

  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  await expect(checkout.successBlock).toContainText('Your order # is:');
});

/**
 * A cart that ships nothing takes a different route through checkout: Magento
 * drops the shipping step and collects a billing address on the payment step
 * instead, which is what makes a digital sale source to the billing address.
 *
 * This asserts the routing, not the tax. Until a billing address is entered
 * there is no address to tax against, so the summary shows Cart Subtotal and
 * Order Total and no tax row at all — reaching one would mean driving the
 * payment-step billing form, a flow no page object here models yet.
 *
 * The tax consequences of that routing are covered where they can be asserted
 * precisely: CatalogTypeLookupTest proves a virtual-only quote is taxed against
 * the billing address and that adding a shippable line moves the whole cart to
 * the shipping address. Digital goods being taxed at all is covered in e2e by
 * the mixed-cart scenario above, which carries a downloadable line.
 */
test('a virtual-only cart checks out with no shipping step', async ({ page }) => {
  test.setTimeout(120_000);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await product.open('test-virtual');
  await product.addToCart();

  await checkout.open();

  // Guest email is collected on the one and only step.
  await expect(checkout.email).toBeVisible({ timeout: 60_000 });

  const steps = page.locator('.opc-progress-bar-item');
  await expect(steps).toHaveCount(1);
  await expect(steps.first()).toContainText('Review & Payments');

  expect(money(await checkout.subtotal.innerText())).toBeCloseTo(10, 2);
});

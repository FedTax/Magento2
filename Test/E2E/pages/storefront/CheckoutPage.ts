import { type Page, type Locator, expect } from '@playwright/test';

/** A guest shipping address for the Luma checkout form. */
export interface GuestAddress {
  email: string;
  firstname: string;
  lastname: string;
  street: string;
  city: string;
  /** State as shown in the region dropdown, e.g. 'Texas'. */
  region: string;
  postcode: string;
  telephone: string;
}

/**
 * Page object for the Luma one-page checkout (guest flow).
 *
 * Note on timing: the order summary's Tax row is populated by a **server-side**
 * TaxCloud SOAP lookup that runs on each totals recompute, so the waits here are
 * deliberately generous — they're bounded by a live API round-trip, not just
 * client rendering. Specs that drive checkout should raise their test timeout
 * accordingly.
 */
export class CheckoutPage {
  readonly page: Page;
  readonly email: Locator;
  readonly placeOrderButton: Locator;
  readonly summary: Locator;
  readonly subtotal: Locator;
  readonly shipping: Locator;
  readonly tax: Locator;
  readonly grandTotal: Locator;
  /** The order-confirmation block (`Your order # is: <number>`). */
  readonly successBlock: Locator;

  constructor(page: Page) {
    this.page = page;
    this.email = page.locator('#customer-email');
    this.placeOrderButton = page
      .locator('button.action.checkout.primary, button.action.primary.checkout')
      .first();

    this.summary = page.locator('.opc-block-summary');
    this.subtotal = this.summary.locator('.totals.sub .amount');
    this.shipping = this.summary.locator('.totals.shipping .amount');
    // The tax row's class is `totals-tax` (hyphen), unlike the others.
    this.tax = this.summary.locator('.totals-tax .amount');
    this.grandTotal = this.summary.locator('.grand.totals .amount');

    this.successBlock = page.locator('.checkout-success');
  }

  /** Open the checkout, optionally on a specific store view (store code in URL). */
  async open(storeCode?: string): Promise<void> {
    const prefix = storeCode ? `/${storeCode}` : '';
    await this.page.goto(`${prefix}/checkout/`);
    await this.email.waitFor({ timeout: 40_000 });
  }

  /**
   * Open the checkout as a signed-in customer.
   *
   * The guest entry point does not transfer: with an account there is no
   * `#customer-email` field to wait on (Magento already knows who this is), and
   * the seeded default address is pre-selected, so there is no address form to
   * fill either. What both flows share is the shipping-method step, so that is
   * what this waits for.
   *
   * Call after {@link loginAsCustomer}; opening this as a guest will time out
   * waiting for a shipping method that never renders.
   */
  async openAsCustomer(storeCode?: string): Promise<void> {
    const prefix = storeCode ? `/${storeCode}` : '';
    await this.page.goto(`${prefix}/checkout/`);
    await this.page
      .locator('input[type="radio"][value="flatrate_flatrate"]')
      .waitFor({ timeout: 60_000 });
  }

  /**
   * Whether the summary shows a Tax row at all.
   *
   * Luma omits the row entirely when tax is zero rather than rendering
   * "$0.00", so an exempt order is asserted by the row's ABSENCE. Reading
   * `tax` directly in that case would hang rather than report zero.
   */
  async hasTaxRow(): Promise<boolean> {
    return (await this.summary.locator('.totals-tax').count()) > 0;
  }

  async fillGuestShipping(a: GuestAddress): Promise<void> {
    await this.email.fill(a.email);
    await this.page.fill('input[name="firstname"]', a.firstname);
    await this.page.fill('input[name="lastname"]', a.lastname);
    await this.page.fill('input[name="street[0]"]', a.street);
    await this.page.fill('input[name="city"]', a.city);
    await this.page.selectOption('select[name="region_id"]', { label: a.region });
    await this.page.fill('input[name="postcode"]', a.postcode);
    await this.page.fill('input[name="telephone"]', a.telephone);
  }

  /**
   * Select Flat Rate shipping and advance to the payment step, then wait until
   * the summary's Tax row carries a value (i.e. the TaxCloud lookup returned).
   *
   * Pass `{ expectTax: false }` for a store where no tax applies — Luma hides
   * the Tax row entirely at zero tax, so waiting for it would time out.
   */
  async selectFlatRateAndContinue(options: { expectTax?: boolean } = {}): Promise<void> {
    const { expectTax = true } = options;
    await this.page
      .locator('input[type="radio"][value="flatrate_flatrate"]')
      .check({ timeout: 40_000 });
    await this.page.locator('button.button.action.continue.primary').first().click();

    await this.placeOrderButton.waitFor({ timeout: 60_000 });
    if (expectTax) {
      await expect(this.tax).toContainText('$', { timeout: 60_000 });
    } else {
      // Give the totals XHR time to land, then let the spec assert on the
      // (absent or zero) tax row.
      await expect(this.grandTotal).toContainText('$', { timeout: 60_000 });
    }
  }

  /** Select Check / Money Order if it isn't already the sole, pre-selected method. */
  async selectCheckMoneyOrder(): Promise<void> {
    const checkmo = this.page.locator('input[type="radio"][value="checkmo"]');
    if (await checkmo.count()) {
      await checkmo.check().catch(() => {});
    }
  }

  async placeOrder(): Promise<void> {
    await this.placeOrderButton.click();
    await this.successBlock.waitFor({ timeout: 90_000 });
  }

  /** The tax amount currently shown in the checkout summary, e.g. "$1.24". */
  async taxAmount(): Promise<string> {
    return (await this.tax.first().innerText()).trim();
  }

  /** The numeric order increment from the confirmation page. */
  async orderNumber(): Promise<string> {
    const text = await this.successBlock.innerText();
    return text.match(/(\d{6,})/)?.[1] ?? '';
  }

  /**
   * Assert the order was placed, for either checkout flow.
   *
   * The two success pages do not read the same: a guest gets "Your order # is:
   * 000000123" as plain text, while a signed-in customer gets "Your order
   * number is:" followed by the number as a link to their order history.
   * Asserting the guest wording against a signed-in checkout fails on an order
   * that was in fact placed successfully — observed here first-hand.
   */
  async expectOrderPlaced(): Promise<string> {
    await expect(this.successBlock).toContainText(/Your order (#|number) is:/);
    const number = await this.orderNumber();
    expect(number).toMatch(/^\d{6,}$/);
    return number;
  }
}

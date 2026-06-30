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

  async open(): Promise<void> {
    await this.page.goto('/checkout/');
    await this.email.waitFor({ timeout: 40_000 });
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
   */
  async selectFlatRateAndContinue(): Promise<void> {
    await this.page
      .locator('input[type="radio"][value="flatrate_flatrate"]')
      .check({ timeout: 40_000 });
    await this.page.locator('button.button.action.continue.primary').first().click();

    await this.placeOrderButton.waitFor({ timeout: 60_000 });
    await expect(this.tax).toContainText('$', { timeout: 60_000 });
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

  /** The numeric order increment from the confirmation page. */
  async orderNumber(): Promise<string> {
    const text = await this.successBlock.innerText();
    return text.match(/(\d{6,})/)?.[1] ?? '';
  }
}

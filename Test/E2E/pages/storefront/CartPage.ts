import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for the Luma cart page and its "Estimate Shipping and Tax" box.
 *
 * The estimator asks for country, state and ZIP only — no street, no city —
 * and every change posts the partial address to the server, which recollects
 * totals (and so runs the TaxCloud lookup). Waits are sized for that live
 * round-trip, as in CheckoutPage.
 */
export class CartPage {
  readonly page: Page;
  readonly estimateHeading: Locator;
  readonly estimateForm: Locator;
  readonly totals: Locator;
  readonly tax: Locator;
  readonly grandTotal: Locator;

  constructor(page: Page) {
    this.page = page;
    this.estimateHeading = page.locator('#block-shipping-heading');
    this.estimateForm = page.locator('#shipping-zip-form');
    this.totals = page.locator('#cart-totals');
    // The tax row's class is `totals-tax` (hyphen), as on the checkout summary.
    this.tax = this.totals.locator('.totals-tax .amount');
    this.grandTotal = this.totals.locator('.grand.totals .amount');
  }

  async open(): Promise<void> {
    await this.page.goto('/checkout/cart/');
    await this.totals.waitFor({ timeout: 40_000 });
  }

  /** Whether the cart totals show a Tax row at all (Luma omits it at zero). */
  async hasTaxRow(): Promise<boolean> {
    return (await this.totals.locator('.totals-tax').count()) > 0;
  }

  /**
   * Fill the estimator with a state and ZIP (country left at the store
   * default, the United States) and pick Flat Rate, so the estimate covers
   * the same product + shipping lines a checkout would.
   */
  async estimate(region: string, postcode: string): Promise<void> {
    // The box is collapsed on first load; opening it only works once RequireJS
    // has bound the collapsible, so retry until the form is visible.
    await expect(async () => {
      if (!(await this.estimateForm.isVisible())) {
        await this.estimateHeading.click();
      }
      await expect(this.estimateForm).toBeVisible({ timeout: 3_000 });
    }).toPass({ timeout: 30_000 });

    await this.estimateForm.locator('select[name="region_id"]').selectOption({ label: region });
    await this.estimateForm.locator('input[name="postcode"]').fill(postcode);
    // The postcode field saves on change, not on every keystroke.
    await this.estimateForm.locator('input[name="postcode"]').blur();

    const flatRate = this.page.locator('input[type="radio"][value="flatrate_flatrate"]');
    await flatRate.waitFor({ timeout: 60_000 });
    if (!(await flatRate.isChecked())) {
      await flatRate.check();
    }
  }
}

import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for a storefront product (PDP) — just enough to add the seeded
 * product to the cart. Extend as product-page coverage grows.
 */
export class ProductPage {
  readonly page: Page;
  readonly addToCartButton: Locator;
  readonly customizeButton: Locator;
  readonly qtyInput: Locator;
  readonly successMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.addToCartButton = page.locator('#product-addtocart-button');
    // Bundles open on a summary view; the options (and the real Add to Cart)
    // are behind this button.
    this.customizeButton = page.locator('#bundle-slide');
    this.qtyInput = page.locator('#qty');
    this.successMessage = page.locator('.message-success').first();
  }

  /**
   * Open a PDP by url key (Magento appends the `.html` suffix).
   *
   * With `web/url/use_store` enabled (the seeded default), pass a storeCode to
   * open the PDP on a specific store view, e.g. 'second' -> /second/....
   * Without one, the default store serves the bare path.
   */
  async open(urlKey: string, storeCode?: string): Promise<void> {
    const prefix = storeCode ? `/${storeCode}` : '';
    await this.page.goto(`${prefix}/${urlKey}.html`);
  }

  /** Click "Add to Cart" and wait for the confirmation message. */
  async addToCart(): Promise<void> {
    await this.addToCartButton.click();
    await expect(this.successMessage).toBeVisible({ timeout: 30_000 });
  }

  /**
   * Choose a configurable option by its label (the seeded configurable's
   * color attribute renders as a plain dropdown).
   */
  async selectConfigurableOption(label: string): Promise<void> {
    const select = this.page.locator('.product-options-wrapper select.super-attribute-select').first();
    await expect(select).toBeVisible({ timeout: 30_000 });
    await select.selectOption({ label });
  }

  /** Set the quantity before adding. */
  async setQty(qty: number): Promise<void> {
    await this.qtyInput.fill(String(qty));
  }

  /**
   * Set the quantity of each associated product on a grouped PDP.
   *
   * Magento pre-fills these from the link quantities, so a grouped product can
   * be added untouched — but setting them explicitly is what proves each
   * association becomes its own line at its own quantity.
   */
  async setGroupedQuantities(quantities: number[]): Promise<void> {
    const inputs = this.page.locator('input[name^="super_group"]');
    await expect(inputs.first()).toBeVisible({ timeout: 30_000 });

    const count = await inputs.count();
    for (let i = 0; i < Math.min(count, quantities.length); i++) {
      await inputs.nth(i).fill(String(quantities[i]));
    }
  }

  /**
   * Add a bundle, revealing its options first.
   *
   * The seeded bundles have every selection default-checked, so no choosing is
   * needed — but the fieldset still has to be open, because the qty box and the
   * live Add to Cart button live inside it.
   */
  async addBundleToCart(qty?: number): Promise<void> {
    // "Customize and Add to Cart" only opens the options once RequireJS has
    // bound its handler, which happens after the load event Playwright waits
    // for. Clicking before that is a no-op that looks exactly like a broken
    // page, so retry the click until the options actually open.
    await expect(async () => {
      await this.customizeButton.click();
      await expect(this.addToCartButton).toBeVisible({ timeout: 3_000 });
    }).toPass({ timeout: 30_000 });

    if (qty !== undefined) {
      await this.qtyInput.fill(String(qty));
    }

    await this.addToCartButton.click();
    await expect(this.successMessage).toBeVisible({ timeout: 30_000 });
  }
}

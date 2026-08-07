import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for a storefront product (PDP) — just enough to add the seeded
 * product to the cart. Extend as product-page coverage grows.
 */
export class ProductPage {
  readonly page: Page;
  readonly addToCartButton: Locator;
  readonly successMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.addToCartButton = page.locator('#product-addtocart-button');
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
}

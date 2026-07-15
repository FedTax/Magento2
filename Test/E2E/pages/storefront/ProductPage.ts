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

  /** Open a PDP by url key (Magento appends the `.html` suffix). */
  async open(urlKey: string): Promise<void> {
    await this.page.goto(`/${urlKey}.html`);
  }

  /** Click "Add to Cart" and wait for the confirmation message. */
  async addToCart(): Promise<void> {
    await this.addToCartButton.click();
    await expect(this.successMessage).toBeVisible({ timeout: 30_000 });
  }
}

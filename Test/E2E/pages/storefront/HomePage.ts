import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for the storefront home page.
 *
 * The first real page object in the suite — it exists mainly to establish the
 * pattern future page objects (CheckoutPage, admin pages, …) will follow:
 * locators in the constructor, navigation + assertions as methods, no raw
 * selectors leaking into specs.
 */
export class HomePage {
  readonly page: Page;

  /** Luma footer carries the "... All rights reserved." copyright line. */
  readonly footer: Locator;

  constructor(page: Page) {
    this.page = page;
    this.footer = page.locator('footer');
  }

  /** Navigate to the storefront root (relative to baseURL). */
  async goto(): Promise<void> {
    await this.page.goto('/');
  }

  /**
   * Assert the home page actually rendered: the default CMS "Home Page" title
   * plus the footer copyright. Together these prove nginx → php-fpm → Magento
   * served a real, themed page rather than an error or a blank response.
   */
  async expectLoaded(): Promise<void> {
    await expect(this.page).toHaveTitle(/Home Page/i);
    await expect(this.page.locator('body')).toContainText(/All rights reserved/i);
  }
}

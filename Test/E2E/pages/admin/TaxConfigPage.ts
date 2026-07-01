import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Admin Stores > Configuration > Sales > Tax > TaxCloud Settings.
 *
 * Deep-linking to the config editor works because the E2E install disables the
 * admin URL secret key (see scripts/install-magento.sh). The API ID / Key fields
 * are plain text (no encryption backend model), so they read back their value.
 */
export class TaxConfigPage {
  readonly page: Page;
  readonly apiId: Locator;
  readonly apiKey: Locator;
  readonly saveButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.apiId = page.locator('#tax_taxcloud_api_id');
    this.apiKey = page.locator('#tax_taxcloud_api_key');
    this.saveButton = page.locator('#save');
  }

  async open(): Promise<void> {
    await this.page.goto('/admin/admin/system_config/edit/section/tax/');
    await this.saveButton.waitFor({ timeout: 40_000 });
    // The TaxCloud Settings group persists its open/closed state per session;
    // expand it if the fields aren't already interactable.
    if (!(await this.apiId.isVisible().catch(() => false))) {
      await this.page.locator('#tax_taxcloud-head').click();
      await expect(this.apiId).toBeVisible({ timeout: 10_000 });
    }
  }

  async readCredentials(): Promise<{ apiId: string; apiKey: string }> {
    return { apiId: await this.apiId.inputValue(), apiKey: await this.apiKey.inputValue() };
  }

  async setCredentials(apiId: string, apiKey: string): Promise<void> {
    await this.apiId.fill(apiId);
    await this.apiKey.fill(apiKey);
  }

  async save(): Promise<void> {
    await this.saveButton.click();
    await expect(this.page.locator('.message-success').first())
      .toContainText('You saved the configuration', { timeout: 40_000 });
  }
}

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
  readonly apiType: Locator;
  readonly apiId: Locator;
  readonly apiKey: Locator;
  readonly restApiKey: Locator;
  readonly restConnectionId: Locator;
  readonly testConnectionButton: Locator;
  readonly testConnectionResult: Locator;
  readonly saveButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.apiType = page.locator('#tax_taxcloud_api_type');
    this.apiId = page.locator('#tax_taxcloud_api_id');
    this.apiKey = page.locator('#tax_taxcloud_api_key');
    this.restApiKey = page.locator('#tax_taxcloud_rest_api_key');
    this.restConnectionId = page.locator('#tax_taxcloud_rest_connection_id');
    this.testConnectionButton = page.locator('#taxcloud_test_connection_btn');
    this.testConnectionResult = page.locator('#taxcloud_test_connection_result');
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

  /**
   * Flip the API Type select without saving — the depends-driven field
   * visibility reacts to the form value alone.
   */
  async selectApiType(value: 'soap' | 'rest'): Promise<void> {
    await this.apiType.selectOption(value);
  }

  /**
   * Click Test Connection and wait for the inline result text (the button
   * posts the current form values via AJAX; no page reload happens).
   */
  async testConnection(): Promise<string> {
    await this.testConnectionButton.click();
    await expect(this.testConnectionResult).toBeVisible({ timeout: 40_000 });
    // "Testing connection…" shows first; wait until the outcome replaces it.
    await expect(this.testConnectionResult).not.toContainText('Testing connection', { timeout: 40_000 });
    return (await this.testConnectionResult.textContent()) ?? '';
  }
}

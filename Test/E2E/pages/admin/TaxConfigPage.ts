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
  readonly exemptionsEnabled: Locator;
  readonly coRdfEnabled: Locator;
  readonly coRdfDeliveryMethods: Locator;
  readonly coRdfAmount: Locator;

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
    this.exemptionsEnabled = page.locator('#tax_taxcloud_exemptions_enabled');
    this.coRdfEnabled = page.locator('#tax_taxcloud_colorado_co_rdf_enabled');
    this.coRdfDeliveryMethods = page.locator('#tax_taxcloud_colorado_co_rdf_delivery_methods');
    this.coRdfAmount = page.locator('#tax_taxcloud_colorado_co_rdf_amount');
  }

  async open(): Promise<void> {
    await this.page.goto('/admin/admin/system_config/edit/section/tax/');
    await this.saveButton.waitFor({ timeout: 40_000 });
    // The TaxCloud Settings group persists its open/closed state per session;
    // expand it if the fields aren't already interactable. The API Type select
    // is the sentinel: unlike the credential fields, it is visible whichever
    // API type is currently saved.
    if (!(await this.apiType.isVisible().catch(() => false))) {
      await this.page.locator('#tax_taxcloud-head').click();
      await expect(this.apiType).toBeVisible({ timeout: 10_000 });
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
   * Turn exemption certificates on or off for the default scope.
   *
   * Off is what a real installation has, and what the seed leaves behind — so
   * a spec needing the feature turns it on and puts it back, rather than the
   * seed pinning a state no merchant starts in.
   *
   * @param enabled whether to offer exemption certificates
   * @param exemptGroupIds customer groups treated as exempt; ignored when disabling
   */
  async setExemptions(enabled: boolean): Promise<void> {
    await this.exemptionsEnabled.selectOption(enabled ? '1' : '0');
  }

  /**
   * Whether the exemption settings are currently switched on in the form.
   */
  async isExemptionsEnabled(): Promise<boolean> {
    return (await this.exemptionsEnabled.inputValue()) === '1';
  }

  /**
   * Expand the nested "Colorado Retail Delivery Fee" group if its enable
   * select is not already interactable (group open/closed state persists per
   * admin session, like the parent group).
   */
  async openColoradoGroup(): Promise<void> {
    if (!(await this.coRdfEnabled.isVisible().catch(() => false))) {
      await this.page.locator('#tax_taxcloud_colorado-head').click();
      await expect(this.coRdfEnabled).toBeVisible({ timeout: 10_000 });
    }
  }

  /**
   * Switch Colorado Retail Delivery Fee collection on (mapping the given
   * shipping methods as motor-vehicle delivery) or off. Off with no mapped
   * methods is the seeded/production default.
   *
   * @param enabled whether to collect the fee
   * @param methods carrier_method codes to map; ignored when disabling
   */
  async setColoradoRdf(enabled: boolean, methods: string[] = []): Promise<void> {
    await this.openColoradoGroup();
    await this.coRdfEnabled.selectOption(enabled ? '1' : '0');
    if (enabled) {
      await expect(this.coRdfDeliveryMethods).toBeVisible({ timeout: 10_000 });
      await this.coRdfDeliveryMethods.selectOption(methods);
    }
  }

  /** Whether fee collection is currently switched on in the form. */
  async isColoradoRdfEnabled(): Promise<boolean> {
    await this.openColoradoGroup();
    return (await this.coRdfEnabled.inputValue()) === '1';
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

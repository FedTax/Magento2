import { type Page, type Locator, expect } from '@playwright/test';
import { waitForOverlaysToClear } from './overlays';

/** What a rule form needs; every filter is optional (empty = any). */
export interface OrderRuleInput {
  name: string;
  action: 'report' | 'calculate_only' | 'skip';
  paymentMethods?: string[];
  orderPrefixes?: string[];
}

/**
 * Admin Stores > Taxes > TaxCloud Order Rules: the ordered rules list and the
 * rule form.
 *
 * Deep links work because the E2E install disables the admin URL secret key
 * (see scripts/install-magento.sh).
 */
export class OrderRulesPage {
  readonly page: Page;
  readonly rows: Locator;
  readonly successMessage: Locator;
  readonly errorMessage: Locator;
  readonly skipWarning: Locator;
  readonly prefixField: Locator;

  constructor(page: Page) {
    this.page = page;
    this.rows = page.locator('[data-role="taxcloud-order-rules"] > tr');
    this.successMessage = page.locator('.message-success').first();
    this.errorMessage = page.locator('.message-error').first();
    this.skipWarning = page.locator('.message-warning', { hasText: 'Skip TaxCloud is decided at checkout' });
    this.prefixField = page.locator('textarea[name="order_prefixes"]');
  }

  async open(): Promise<void> {
    await this.page.goto('/admin/taxcloud/orderrule/');
    await this.page.locator('.taxcloud-order-rules').waitFor({ timeout: 30_000 });
  }

  /** The row of a rule, by its exact name. */
  row(name: string): Locator {
    return this.rows.filter({ has: this.nameLink(name) });
  }

  /** The name link of a rule — exact, so "Rule A" never matches "Rule A (renamed)". */
  private nameLink(name: string): Locator {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return this.page.locator('td a', { hasText: new RegExp(`^\\s*${escaped}\\s*$`) });
  }

  /** Rule names in evaluation order, as the list shows them. */
  async names(): Promise<string[]> {
    return (await this.rows.locator('td:nth-child(2) a').allInnerTexts()).map((n) => n.trim());
  }

  async openNew(): Promise<void> {
    await this.open();
    await waitForOverlaysToClear(this.page);
    await this.page.locator('button#add').click();
    await this.page.locator('input[name="name"]').waitFor({ timeout: 30_000 });
  }

  async openEdit(name: string): Promise<void> {
    await this.open();
    await this.row(name).locator(this.nameLink(name)).click();
    await this.page.locator('input[name="name"]').waitFor({ timeout: 30_000 });
  }

  async fill(rule: OrderRuleInput): Promise<void> {
    await this.page.locator('input[name="name"]').fill(rule.name);
    await this.page.locator('select[name="action"]').selectOption(rule.action);
    if (rule.paymentMethods) {
      await this.page.locator('select[name="payment_methods"]').selectOption(rule.paymentMethods);
    }
    if (rule.orderPrefixes && rule.action !== 'skip') {
      await this.prefixField.fill(rule.orderPrefixes.join('\n'));
    }
  }

  async save(): Promise<void> {
    await waitForOverlaysToClear(this.page);
    await this.page.locator('button#save').click();
  }

  /** Create a rule through the form and land back on the list. */
  async create(rule: OrderRuleInput): Promise<void> {
    await this.openNew();
    await this.fill(rule);
    await this.save();
    await expect(this.successMessage).toContainText('The order rule has been saved', { timeout: 30_000 });
    await expect(this.row(rule.name)).toHaveCount(1);
  }

  /** Delete a rule from the list, confirming the dialog. No-op when absent. */
  async delete(name: string): Promise<void> {
    await this.open();
    const row = this.row(name);
    if ((await row.count()) === 0) {
      return;
    }
    await row.locator('button', { hasText: 'Delete' }).click();
    await this.page.locator('.modal-popup.confirm._show button.action-accept').click();
    await expect(this.successMessage).toContainText('The order rule has been deleted', { timeout: 30_000 });
  }

  /**
   * Drag one rule's handle onto another rule's row. Moved in steps: jQuery UI
   * sortable only reacts to a pointer that travels, not to a jump.
   */
  async drag(name: string, ontoName: string): Promise<void> {
    const handle = this.row(name).locator('[data-role="drag-handle"]');
    const target = this.row(ontoName);
    const from = await handle.boundingBox();
    const to = await target.boundingBox();
    if (!from || !to) {
      throw new Error('Rule rows are not visible');
    }

    const saved = this.page.waitForResponse((r) => r.url().includes('/taxcloud/orderrule/saveOrder'));
    await this.page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await this.page.mouse.down();
    await this.page.mouse.move(from.x + from.width / 2, to.y + 2, { steps: 15 });
    await this.page.mouse.move(from.x + from.width / 2, to.y + 1, { steps: 5 });
    await this.page.mouse.up();
    expect((await saved).ok()).toBeTruthy();
  }
}

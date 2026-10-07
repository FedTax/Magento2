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

  /**
   * Delete every rule with this name, confirming each dialog. No-op when
   * absent; a failed earlier attempt can leave more than one.
   */
  async delete(name: string): Promise<void> {
    await this.open();
    while ((await this.row(name).count()) > 0) {
      await waitForOverlaysToClear(this.page);
      await this.row(name).first().locator('button', { hasText: 'Delete' }).click();
      await this.page.locator('.modal-popup.confirm._show button.action-accept').click();
      await expect(this.successMessage).toContainText('The order rule has been deleted', { timeout: 30_000 });
      await this.open();
    }
  }

  /**
   * Drag one rule's handle onto another rule's row. Moved in steps: jQuery UI
   * sortable only reacts to a pointer that travels, not to a jump.
   *
   * The admin can push the table down after load — the system messages banner
   * ("Cache Types are invalidated") arrives asynchronously — so the layout is
   * first left to settle and both rows are measured only then, immediately
   * before the press. Measuring at page load grabbed the wrong row on a
   * loaded CI runner.
   *
   * Nothing is re-measured once the drag is under way: the first move already
   * lifts the row and shifts the target down by a placeholder, and following
   * the target down would put the row straight back — order unchanged, no
   * save sent.
   */
  async drag(name: string, ontoName: string): Promise<void> {
    await this.waitForStableLayout();

    const handle = this.row(name).locator('[data-role="drag-handle"]');
    const from = await this.box(handle);
    const to = await this.box(this.row(ontoName));
    const saved = this.page.waitForResponse(
      (r) => r.url().includes('/taxcloud/orderrule/saveOrder'),
      { timeout: 30_000 },
    );

    const x = from.x + from.width / 2;
    await this.page.mouse.move(x, from.y + from.height / 2);
    await this.page.mouse.down();
    await this.page.mouse.move(x, to.y + 2, { steps: 15 });
    await this.page.mouse.move(x, to.y + 1, { steps: 5 });
    await this.page.mouse.up();

    expect((await saved).ok()).toBeTruthy();
  }

  /** Wait until the rules table stops moving (two equal readings 250 ms apart). */
  private async waitForStableLayout(): Promise<void> {
    const table = this.page.locator('.taxcloud-order-rules__table');
    let previous = -1;
    await expect.poll(async () => {
      const y = (await table.boundingBox())?.y ?? -2;
      const stable = y === previous;
      previous = y;
      return stable;
    }, { timeout: 15_000, intervals: [250] }).toBe(true);
  }

  private async box(locator: Locator): Promise<{ x: number; y: number; width: number; height: number }> {
    const box = await locator.boundingBox();
    if (!box) {
      throw new Error('Rule row is not visible');
    }
    return box;
  }
}

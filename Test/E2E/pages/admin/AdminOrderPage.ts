import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Admin Sales > Orders: open an order by its increment id and drive the
 * invoice / credit-memo actions a CS rep uses.
 *
 * Note: no `networkidle` waits — admin pages keep background requests open and
 * never reach network idle, so we wait on concrete elements instead.
 */
export class AdminOrderPage {
  readonly page: Page;
  readonly successMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.successMessage = page.locator('.message-success').first();
  }

  /** Find the order in the grid by increment id and open its view page. */
  async openByIncrement(increment: string): Promise<void> {
    await this.page.goto('/admin/sales/order/');
    const search = this.page.locator('.data-grid-search-control').first();
    await search.waitFor({ timeout: 30_000 });
    await search.fill(increment);
    await search.press('Enter');
    await this.page.waitForTimeout(2500);
    await this.page
      .locator('.admin__data-grid-loading-mask')
      .waitFor({ state: 'hidden', timeout: 20_000 })
      .catch(() => {});
    await this.page
      .locator(`tr:has-text("${increment}") a:has-text("View")`)
      .first()
      .click({ timeout: 20_000 });
    await this.page.locator('#order_status').waitFor({ timeout: 30_000 });
  }

  /** Create + submit an offline invoice for the whole order. */
  async createInvoice(): Promise<void> {
    await this.page.locator('button:has-text("Invoice")').first().click();
    const submit = this.page.locator('button:has-text("Submit Invoice")').first();
    await submit.waitFor({ timeout: 30_000 });
    await submit.click();
    await expect(this.page.locator('.message-success').first())
      .toContainText('invoice has been created', { timeout: 60_000 });
  }

  /** Create a full credit memo and refund it offline (fires the TaxCloud return). */
  async refundOffline(): Promise<void> {
    const creditMemo = this.page.locator('button:has-text("Credit Memo")').first();
    await creditMemo.waitFor({ timeout: 30_000 });
    await creditMemo.click();
    const refund = this.page.locator('button:has-text("Refund Offline")').first();
    await refund.waitFor({ timeout: 30_000 });
    await refund.click();
    await expect(this.page.locator('.message-success').first())
      .toContainText('created the credit memo', { timeout: 60_000 });
  }

  /**
   * Create a PARTIAL credit memo — refund only `qty` units of the first order
   * item — and refund it offline. Opens the New Credit Memo form, adjusts the
   * quantity, recalculates via "Update Qty's", zeroes refund shipping, then
   * submits.
   */
  async refundOfflinePartial(qty: number): Promise<void> {
    const creditMemo = this.page.locator('button:has-text("Credit Memo")').first();
    await creditMemo.waitFor({ timeout: 30_000 });
    await creditMemo.click();

    const qtyInput = this.page.locator('input.qty-input').first();
    await qtyInput.waitFor({ timeout: 30_000 });
    await qtyInput.fill(String(qty));
    await this.page.locator('button:has-text("Update Qty\'s")').first().click();
    await this.page.locator('input.qty-input').first().waitFor({ timeout: 30_000 });

    // Keep the shipping charge: the delivery happened.
    const shippingRefund = this.page.locator('#shipping_amount');
    if (await shippingRefund.isVisible().catch(() => false)) {
      await shippingRefund.fill('0');
      await shippingRefund.blur();
    }

    const refund = this.page.locator('button:has-text("Refund Offline")').first();
    await refund.waitFor({ timeout: 30_000 });
    await refund.click();
    await expect(this.page.locator('.message-success').first())
      .toContainText('created the credit memo', { timeout: 60_000 });
  }

  /**
   * Whether the New Credit Memo form currently shows a "Colorado Retail
   * Delivery Fee" row in its refund totals. Call between opening the memo
   * form and submitting it.
   */
  async creditMemoShowsRdfRow(): Promise<boolean> {
    return (await this.page.locator('tr:has-text("Colorado Retail Delivery Fee")').count()) > 0;
  }

  async status(): Promise<string> {
    return (await this.page.locator('#order_status').first().innerText()).trim();
  }

  /** The "Total Refunded" amount from the order-view totals, e.g. "$16.24". */
  async totalRefunded(): Promise<string> {
    return (await this.page.locator('tr:has-text("Total Refunded") .price').first().innerText()).trim();
  }

  /** The "Grand Total" amount from the order-view totals, e.g. "$16.55". */
  async grandTotal(): Promise<string> {
    return (await this.page.locator('tr:has-text("Grand Total") .price').first().innerText()).trim();
  }

  /**
   * A named row from the order-view totals (e.g. "Colorado Retail Delivery
   * Fee"), or null when no such row is rendered.
   */
  async totalsRowAmount(label: string): Promise<string | null> {
    const row = this.page.locator(`tr:has-text("${label}") .price`).first();
    if ((await row.count()) === 0) {
      return null;
    }
    return (await row.innerText()).trim();
  }
}

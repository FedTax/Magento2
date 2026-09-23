import { type Page, type Locator, expect } from '@playwright/test';
import { waitForOverlaysToClear } from './overlays';

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
  /** "TaxCloud Diagnostics" in the order-view button bar. */
  readonly diagnosticsButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.successMessage = page.locator('.message-success').first();
    this.diagnosticsButton = page.locator('button#taxcloud_diagnostics');
  }

  /** Find the order in the grid by increment id and open its view page. */
  async openByIncrement(increment: string): Promise<void> {
    await this.page.goto('/admin/sales/order/');
    const search = this.page.locator('.data-grid-search-control').first();
    await search.waitFor({ timeout: 30_000 });
    await search.fill(increment);
    await search.press('Enter');

    // Wait for the searched row itself, rather than sleeping and hoping the
    // grid has reloaded: the grid re-renders asynchronously, and a fixed pause
    // is either wasted time or — on a loaded runner — too short, in which case
    // the click lands on the pre-search row set.
    const viewLink = this.page.locator(`tr:has-text("${increment}") a:has-text("View")`).first();
    await viewLink.waitFor({ timeout: 40_000 });

    // And nothing may be covering it. An overlay steals the click, and
    // Playwright then retries for 20s before reporting a timeout that names
    // the button rather than the modal on top of it.
    await waitForOverlaysToClear(this.page);

    await viewLink.click({ timeout: 20_000 });
    await this.page.locator('#order_status').waitFor({ timeout: 30_000 });
  }

  /** Create + submit an offline invoice for the whole order. */
  async createInvoice(): Promise<void> {
    await waitForOverlaysToClear(this.page);
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
    await waitForOverlaysToClear(this.page);
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

    // Reduce the refund quantity. Magento enables "Update Qty's" only when a
    // qty field fires a `change` event whose value differs from the value
    // captured at load (items.phtml checkButtonsRelation) — .fill() alone does
    // not reliably fire it, leaving the button disabled forever, so dispatch
    // `change` explicitly. Target the button by its stable class, not its
    // apostrophe'd label, and wait for it to actually enable before clicking.
    const qtyInput = this.page.locator('input.qty-input').first();
    await qtyInput.waitFor({ timeout: 30_000 });
    await qtyInput.fill(String(qty));
    await qtyInput.dispatchEvent('change');

    const updateButton = this.page.locator('button.update-button').first();
    await expect(updateButton).toBeEnabled({ timeout: 15_000 });
    await updateButton.click();

    // "Update Qty's" reloads the credit-memo form with recalculated totals.
    const refund = this.page.locator('button:has-text("Refund Offline")').first();
    await refund.waitFor({ timeout: 30_000 });
    await refund.click();
    await expect(this.page.locator('.message-success').first())
      .toContainText('created the credit memo', { timeout: 60_000 });
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

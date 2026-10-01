import { type Page, type Locator, type Download, expect } from '@playwright/test';
import * as fs from 'fs';
import { readZip } from '../../fixtures/zip';

export interface DiagnosticsOptions {
  maskCustomerDetails?: boolean;
  logWindow?: 'standard' | 'extended' | 'maximum';
  testConnection?: boolean;
}

/**
 * The "Download TaxCloud diagnostics" dialog opened by both diagnostics
 * buttons (TaxCloud Settings and the order view). Generating submits a real
 * form POST whose response is the ZIP attachment, surfaced by Playwright as a
 * download.
 */
export class DiagnosticsDialog {
  readonly page: Page;
  readonly modal: Locator;

  constructor(page: Page) {
    this.page = page;
    this.modal = page.locator('.modal-popup._show').filter({ hasText: 'Download TaxCloud diagnostics' });
  }

  /** Open the dialog from a diagnostics button and wait for it to show. */
  async openFrom(button: Locator): Promise<void> {
    await expect(button).toBeVisible({ timeout: 30_000 });
    // The button is rendered before RequireJS binds its click handler; a click
    // in between does nothing. The widget marks the button once bound.
    await expect(button).toHaveAttribute('data-taxcloud-diagnostics-ready', '1', { timeout: 30_000 });
    await button.click();
    await expect(this.modal).toBeVisible({ timeout: 15_000 });
  }

  async setOptions(options: DiagnosticsOptions): Promise<void> {
    const mask = this.modal.locator('input[name="redact_pii"]');
    const probe = this.modal.locator('input[name="probe"]');
    await mask.setChecked(options.maskCustomerDetails ?? false);
    await probe.setChecked(options.testConnection ?? true);
    await this.modal.locator('select[name="log_window"]').selectOption(options.logWindow ?? 'standard');
  }

  /** Click Generate and download; return the download and its unzipped entries. */
  async generate(): Promise<{ download: Download; entries: Map<string, string> }> {
    const downloadPromise = this.page.waitForEvent('download', { timeout: 120_000 });
    await this.modal.getByRole('button', { name: 'Generate and download' }).click();
    const download = await downloadPromise;
    const path = await download.path();
    if (path === null) {
      throw new Error('diagnostics download did not complete: ' + (await download.failure()));
    }

    return { download, entries: readZip(fs.readFileSync(path)) };
  }
}

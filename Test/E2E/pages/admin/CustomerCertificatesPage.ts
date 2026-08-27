import { type Page, type Locator, expect } from '@playwright/test';

/**
 * The exemption-certificate panel on the customer edit page.
 *
 * It is a customer TAB, which is the whole reason this page object exists:
 * knockout injects tab content after Magento's component bootstrap has run, and
 * the stock tab template renders through knockout's native `html` binding
 * rather than Magento's `bindHtml` — so neither `text/x-magento-init` nor
 * `data-mage-init` is ever applied inside it. The panel has twice rendered
 * perfectly and made no requests at all. Nothing but a browser catches that,
 * which is why `expectLoaded()` asserts on a network call and not on markup.
 */
export class CustomerCertificatesPage {
  readonly page: Page;

  /** Certificate ids requested by the panel, in order, as method + path. */
  readonly calls: string[] = [];

  constructor(page: Page) {
    this.page = page;

    // Registered once, here: delete asks for confirmation, and a handler added
    // per call stacks up until the second one throws "already handled" and
    // leaves the panel stuck mid-delete.
    page.on('dialog', (d) => {
      d.accept().catch(() => undefined);
    });

    page.on('request', (r) => {
      if (/taxcloud\/certificate/i.test(r.url())) {
        this.calls.push(`${r.method()} ${r.url().split('/admin')[1]?.split('/key/')[0] ?? ''}`);
      }
    });
  }

  async openCustomer(customerId: number): Promise<void> {
    await this.page.goto(`/admin/customer/index/edit/id/${customerId}/`);
    await this.page.waitForLoadState('networkidle');
    // The form renders client-side; the tab strip is the first thing to settle.
    await this.page.locator('.admin__page-nav-item').first().waitFor({ timeout: 60_000 });
  }

  async tabLabels(): Promise<string[]> {
    return this.page.evaluate(() =>
      Array.from(document.querySelectorAll('.admin__page-nav-item'))
        .map((e) => (e as HTMLElement).innerText.trim().split('\n')[0])
        .filter(Boolean));
  }

  get tab(): Locator {
    return this.page.locator('.admin__page-nav-item').filter({ hasText: 'TaxCloud' });
  }

  get panel(): Locator {
    return this.page.locator('[data-taxcloud-certificates]');
  }

  get rows(): Locator {
    return this.page.locator('[data-role="certificate-rows"] tr');
  }

  get status(): Locator {
    return this.page.locator('[data-role="status"]');
  }

  /** Open the tab and wait until the panel has actually talked to Magento. */
  async openTab(): Promise<void> {
    const before = this.calls.length;
    await this.tab.click();

    await expect
      .poll(() => this.calls.length, {
        message: 'the panel must request its certificates when the tab opens — ' +
          'rendering without asking is the failure mode this spec exists for',
        timeout: 60_000,
      })
      .toBeGreaterThan(before);

    // Let the listing render before anything reads rows.
    await expect(this.status).not.toHaveText(/Reading certificates/, { timeout: 60_000 });
  }

  /** The certificate the customer's orders are filed against, '' when none. */
  async attachedId(): Promise<string> {
    return this.page.evaluate(() => {
      const row = Array.from(document.querySelectorAll('[data-role="certificate-rows"] tr'))
        .find((r) => /In use/.test((r as HTMLElement).innerText));
      return row ? (row.querySelector('code')?.textContent || '').trim() : '';
    });
  }

  /** Rows keyed by certificate id, so a test can name the one it means. */
  async certificateIds(): Promise<string[]> {
    return this.page.evaluate(() =>
      Array.from(document.querySelectorAll('[data-role="certificate-rows"] tr code'))
        .map((c) => (c.textContent || '').trim()));
  }

  rowFor(certificateId: string): Locator {
    return this.rows.filter({ hasText: certificateId });
  }

  async attach(certificateId: string): Promise<void> {
    await this.page.locator(`[data-attach="${certificateId}"]`).first().click();
    await this.settle();
  }

  async clearAttachment(): Promise<void> {
    await this.page.locator('[data-attach=""]').first().click();
    await this.settle();
  }

  /**
   * Create a certificate covering Texas, named so cleanup can find it again.
   * Returns its id.
   */
  async createCertificate(lastName: string): Promise<string> {
    const before = await this.certificateIds();

    await this.page.locator('[data-role="show-add"]').click();
    await this.page.selectOption('#tc_cert_states', ['TX']);
    await this.page.fill('#tc_cert_firstname', 'E2E');
    await this.page.fill('[data-field="lastName"]', lastName);
    await this.page.fill('#tc_cert_address1', '1401 Lavaca St');
    await this.page.fill('#tc_cert_city', 'Austin');
    await this.page.selectOption('#tc_cert_state', 'TX');
    await this.page.fill('#tc_cert_zip', '78701');
    await this.page.locator('[data-role="save"]').click();
    await this.settle();

    await expect
      .poll(async () => (await this.certificateIds()).length, { timeout: 90_000 })
      .toBeGreaterThan(before.length);

    const after = await this.certificateIds();
    const created = after.find((id) => !before.includes(id));

    if (!created) {
      throw new Error('the certificate was reported saved but did not appear in the list');
    }

    return created;
  }

  /** Remove every certificate this suite created, whatever state it left. */
  async deleteCertificatesNamed(marker: string): Promise<void> {
    for (;;) {
      const row = this.rows.filter({ hasText: marker });

      if ((await row.count()) === 0) {
        return;
      }

      await row.first().locator('[data-delete]').click();
      await this.settle();
    }
  }

  async refresh(): Promise<void> {
    await this.page.locator('[data-role="refresh"]').click();
    await this.settle();
  }

  /** Wait for the panel to stop working, however it got busy. */
  private async settle(): Promise<void> {
    await expect(this.status).not.toHaveText(/Reading|Attaching|Clearing|Deleting|Discarding/, {
      timeout: 90_000,
    });
  }
}

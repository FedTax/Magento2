import { test, expect } from '../../fixtures/taxcloudLog';
import { type Page } from '@playwright/test';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrderPage } from '../../pages/admin/AdminOrderPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';
import { DiagnosticsDialog } from '../../pages/admin/DiagnosticsDialog';

/**
 * Diagnostics export through the real admin UI.
 *
 * Integration tests prove what goes into a bundle; only this proves the parts a
 * merchant touches: the buttons render, the dialog states what the file will
 * contain before anything is generated, the form POST carries the form key and
 * turns into a browser download, and an admin without the TaxCloud Diagnostics
 * Export grant can neither see the buttons nor call the route.
 *
 * The live connection test is switched off in every run — these specs must not
 * depend on the network — and is covered by unit tests.
 */

const EXPORT_URL = '/admin/taxcloud/diagnostics/export/';
const RESTRICTED_ADMIN = 'tax-no-diagnostics';
const ADMIN_PASSWORD = '1234567a';

const TX_ADDRESS: GuestAddress = {
  email: 'diagnostics-guest@example.com',
  firstname: 'Diagnostics',
  lastname: 'Shopper',
  street: '1401 Lavaca St',
  city: 'Austin',
  region: 'Texas',
  postcode: '78701',
  telephone: '5125550199',
};

async function placeGuestOrder(page: Page): Promise<string> {
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);
  await product.open('test-product');
  await product.addToCart();
  await checkout.open();
  await checkout.fillGuestShipping(TX_ADDRESS);
  await checkout.selectFlatRateAndContinue();
  await checkout.selectCheckMoneyOrder();
  await checkout.placeOrder();
  const orderNo = await checkout.orderNumber();
  expect(orderNo).toMatch(/^\d+$/);

  return orderNo;
}

/** POST to the export route with the page's admin session, bypassing the UI. */
async function postExport(page: Page, withFormKey: boolean) {
  const formKey = await page.evaluate(() => (window as unknown as { FORM_KEY?: string }).FORM_KEY ?? '');
  const form: Record<string, string> = { probe: '0', redact_pii: '0', log_window: 'standard' };
  if (withFormKey) {
    form.form_key = formKey;
  }

  return page.request.post(EXPORT_URL, { form, maxRedirects: 5 });
}

test.describe.serial('diagnostics export', () => {
  let orderNo = '';

  test('Download Diagnostics on TaxCloud Settings produces the bundle', async ({ page }) => {
    test.setTimeout(180_000);
    await new AdminLoginPage(page).login();
    const config = new TaxConfigPage(page);
    await config.open();

    // Read what the bundle must never contain, straight from the form.
    const credentials = await config.readCredentials();
    expect(credentials.apiKey.length).toBeGreaterThan(8);

    const dialog = new DiagnosticsDialog(page);
    await dialog.openFrom(config.downloadDiagnosticsButton);
    // The merchant is told what the file holds before anything is generated.
    await expect(dialog.modal).toContainText('contains customer order data');
    await expect(dialog.modal).toContainText('credentials are never included');
    await expect(dialog.modal).toContainText('live tax calculation and address check');

    await dialog.setOptions({ maskCustomerDetails: false, testConnection: false });
    const { download, entries } = await dialog.generate();

    expect(download.suggestedFilename()).toMatch(/^taxcloud-diagnostics-default-\d{8}-\d{6}\.zip$/);
    for (const file of ['summary.md', 'manifest.json', 'settings.json', 'magento-tax.json', 'modules.json',
      'environment.json', 'collector-diagnostics.json', 'probe.json']) {
      expect(entries.has(file), `${file} is in the bundle`).toBe(true);
    }

    const manifest = JSON.parse(entries.get('manifest.json') ?? '{}');
    expect(manifest.origin).toBe('admin');
    expect(manifest.generated_by).toBe('admin');
    expect(manifest.probe_enabled).toBe(false);
    expect(manifest.redaction.customer_details).toBe('included as-is');
    expect(manifest.scope.stores.length).toBeGreaterThanOrEqual(2);
    expect(manifest.failures).toEqual([]);

    expect(entries.get('summary.md')).toContain('## Blockers');

    for (const [name, content] of entries) {
      expect(content, `${name} leaks the API ID`).not.toContain(credentials.apiId);
      expect(content, `${name} leaks the API Key`).not.toContain(credentials.apiKey);
    }
  });

  test('TaxCloud Diagnostics on the order view produces a masked per-order bundle', async ({ page }) => {
    test.setTimeout(300_000);
    orderNo = await placeGuestOrder(page);

    await new AdminLoginPage(page).login();
    const order = new AdminOrderPage(page);
    await order.openByIncrement(orderNo);

    const dialog = new DiagnosticsDialog(page);
    await dialog.openFrom(order.diagnosticsButton);
    await expect(dialog.modal).toContainText('describes this order');
    await dialog.setOptions({ maskCustomerDetails: true, testConnection: false });
    const { entries } = await dialog.generate();

    const orderJson = JSON.parse(entries.get('order.json') ?? '{}');
    expect(orderJson.increment_id).toBe(orderNo);
    expect(orderJson.items.lines.length).toBeGreaterThan(0);
    expect(orderJson.items.lines[0].tic_source).toBeTruthy();

    // Masked: what identifies the shopper. Kept: what the tax depends on.
    expect(orderJson.shipping_address.firstname).toBe('***MASKED***');
    expect(orderJson.shipping_address.postcode).toBe(TX_ADDRESS.postcode);
    expect(orderJson.shipping_address.city).toBe(TX_ADDRESS.city);
    for (const [name, content] of entries) {
      expect(content, `${name} contains the shopper's email`).not.toContain(TX_ADDRESS.email);
      expect(content, `${name} contains the shopper's street`).not.toContain(TX_ADDRESS.street);
      expect(content, `${name} contains the shopper's phone`).not.toContain(TX_ADDRESS.telephone);
    }

    // The log is narrowed to this order: its checkout (by quote id) and its
    // capture (by increment id), plus at most CONTEXT records either side of
    // each run of matches — which may legitimately belong to other orders.
    const CONTEXT = 5;
    const log = entries.get('logs/taxcloud.log') ?? '';
    expect(log).toContain(`"order_increment_id":"${orderNo}"`);
    expect(log).toContain(`"quote_id":"${orderJson.quote_id}"`);
    const lines = log.split('\n');
    const groups = lines.filter((line) => line === '--').length + 1;
    const records = lines.filter((line) => line.startsWith('['));
    const ours = records.filter(
      (line) => line.includes(orderNo) || line.includes(`"quote_id":"${orderJson.quote_id}"`),
    );
    expect(ours.length).toBeGreaterThan(0);
    expect(records.length - ours.length).toBeLessThanOrEqual(2 * CONTEXT * groups);

    const manifest = JSON.parse(entries.get('manifest.json') ?? '{}');
    expect(manifest.order_increment_id).toBe(orderNo);
    expect(manifest.scope.stores.map((store: { code: string }) => store.code)).toEqual(['default']);
    expect(manifest.redaction.customer_details).toBe('masked');
  });

  test('an admin without the diagnostics grant sees no buttons and is refused by the route', async ({ page }) => {
    test.setTimeout(180_000);
    expect(orderNo, 'the previous test placed an order').not.toBe('');

    await new AdminLoginPage(page).login(RESTRICTED_ADMIN, ADMIN_PASSWORD);

    const config = new TaxConfigPage(page);
    await config.open();
    // The restricted role can use the rest of the group...
    await expect(config.testConnectionButton).toBeVisible();
    // ...but not the export.
    await expect(config.downloadDiagnosticsButton).toHaveCount(0);

    const order = new AdminOrderPage(page);
    await order.openByIncrement(orderNo);
    // The order view itself is allowed (openByIncrement waited for it)...
    await expect(page.locator('#order_status')).toBeVisible();
    await expect(order.diagnosticsButton).toHaveCount(0);

    const response = await postExport(page, true);
    expect(response.headers()['content-type'] ?? '').not.toContain('application/zip');
    expect(response.headers()['content-disposition'] ?? '').not.toContain('attachment');
    expect(await response.text()).toContain('need permissions');
  });

  test('the export route rejects a POST without the form key', async ({ page }) => {
    test.setTimeout(120_000);
    await new AdminLoginPage(page).login();
    await new TaxConfigPage(page).open();

    const refused = await postExport(page, false);
    expect(refused.headers()['content-type'] ?? '').not.toContain('application/zip');
    expect(refused.headers()['content-disposition'] ?? '').not.toContain('attachment');

    // Control: the same request with the form key is served, so the refusal
    // above is the form key and not something else about the request.
    const served = await postExport(page, true);
    expect(served.headers()['content-type']).toContain('application/zip');
    expect(served.headers()['content-disposition']).toMatch(/attachment; filename="taxcloud-diagnostics-/);
  });
});

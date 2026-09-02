import { test, expect, type Page, type Locator } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';
import * as path from 'path';

/**
 * Generates the screenshots used by the documentation site.
 *
 * NOT a test — nothing here asserts behaviour. It drives the seeded E2E store
 * to each documented screen and writes a cropped PNG into docs/images/, so the
 * images in the docs can be regenerated rather than re-taken by hand when the
 * admin UI changes.
 *
 *   make e2e-setup          # once — installs the throwaway store
 *   make docs-screenshots   # regenerates every image
 *
 * Why the E2E store and not a real one: its admin credentials are already
 * public in this repository, 2FA is off, and the catalogue/customers are seeded
 * fixtures. A screenshot of a real store would put live API credentials and
 * real customers' details on a public website.
 *
 * Credentials are still masked or replaced even here, because the .env used to
 * install this store carries real TaxCloud sandbox keys.
 *
 * Each screenshot is its own test so that one broken selector costs one image
 * rather than the whole set. They run in the `docs-screenshots` project, which
 * the ordinary suite excludes.
 */

const IMAGES = path.resolve(__dirname, '../../../../docs/images');

/** Placeholder credentials, so no real key is ever in frame. */
const PLACEHOLDER_CONNECTION_ID = '25eb9b97-5acb-492d-b720-c03e79cf715a';
const PLACEHOLDER_API_ID = '12345678';
const PLACEHOLDER_API_KEY = 'ABCDEFGH-1234-5678-ABCD-EFGH12345678';

/**
 * Shoot one element rather than the viewport: a full-page admin screenshot is
 * mostly Magento chrome the reader already knows, and it changes with every
 * Magento release for reasons that have nothing to do with this extension.
 *
 * `padding` widens the box so the element does not sit flush against the crop.
 */
async function shoot(target: Locator, name: string, opts: { mask?: Locator[] } = {}): Promise<void> {
  await target.scrollIntoViewIfNeeded();
  await target.page().waitForTimeout(300); // let scroll/animation settle
  await target.screenshot({
    path: path.join(IMAGES, name),
    mask: opts.mask,
    maskColor: '#cfd8dc',
    animations: 'disabled',
  });
}

/**
 * Screenshot the span from one element to another — for a run of config rows
 * that belong together but share no single wrapper of their own. Playwright can
 * only shoot one element, so this clips the page to the union of the two boxes.
 */
async function shootRange(
  page: Page,
  first: Locator,
  last: Locator,
  name: string,
): Promise<void> {
  // boundingBox() is viewport-relative; a fullPage clip is document-relative.
  // Scrolling to the top makes the two coincide, so the range can extend below
  // the fold without the clip sliding off it.
  await first.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
  const a = await first.boundingBox();
  const b = await last.boundingBox();
  if (!a || !b) throw new Error(`cannot measure the range for ${name}`);
  const top = Math.min(a.y, b.y);
  const bottom = Math.max(a.y + a.height, b.y + b.height);
  await page.screenshot({
    path: path.join(IMAGES, name),
    fullPage: true,
    clip: {
      x: Math.min(a.x, b.x),
      y: top,
      width: Math.max(a.x + a.width, b.x + b.width) - Math.min(a.x, b.x),
      height: bottom - top,
    },
    animations: 'disabled',
  });
}

/**
 * The visible admin grid. `.data-grid` also matches a hidden sticky-header
 * clone, and `.first()` picks whichever is first in the DOM — which is the
 * hidden one, so a plain wait never resolves.
 */
function visibleGrid(page: Page): Locator {
  return page.locator('table.data-grid').locator('visible=true').first();
}

/** Filter an admin grid by keyword and open the matching row's edit page. */
async function openGridRow(page: Page, url: string, keyword: string): Promise<void> {
  await page.goto(url);
  const search = page.locator('.data-grid-search-control').first();
  await search.waitFor({ timeout: 40_000 });
  await search.fill(keyword);
  await search.press('Enter');
  await page.locator('.admin__data-grid-loading-mask')
    .waitFor({ state: 'hidden', timeout: 30_000 })
    .catch(() => {});
  await page.waitForTimeout(1500);
  await page.locator(`tr:has-text("${keyword}") a:has-text("Edit")`).first()
    .click({ timeout: 30_000 });
}

/** Admin config section, with the TaxCloud group expanded. */
async function openTaxConfig(page: Page): Promise<TaxConfigPage> {
  const config = new TaxConfigPage(page);
  await config.open();
  return config;
}

/** Open the exempt customer's TaxCloud certificate tab, loaded. */
async function openCertificateTab(page: Page): Promise<void> {
  await openGridRow(page, '/admin/customer/index/', 'exempt-customer@example.com');
  await page.locator('.admin__page-nav-item').first().waitFor({ timeout: 60_000 });
  await page.locator('.admin__page-nav-item').filter({ hasText: 'TaxCloud' }).click();
  await page.locator('[data-taxcloud-certificates]').waitFor({ timeout: 60_000 });
}

test.beforeEach(async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await new AdminLoginPage(page).login();
});

test.describe('documentation screenshots', () => {
  test('shipping origin', async ({ page }) => {
    await page.goto('/admin/admin/system_config/edit/section/shipping/');
    const group = page.locator('#shipping_origin');
    if (!(await group.isVisible().catch(() => false))) {
      await page.locator('#shipping_origin-head').click();
    }
    await expect(page.locator('#shipping_origin_postcode')).toBeVisible({ timeout: 30_000 });
    await shoot(page.locator('#shipping_origin').locator('..'), 'quickstart-shipping-origin.png');
  });

  test('taxcloud settings', async ({ page }) => {
    const config = await openTaxConfig(page);
    await config.selectApiType('rest');
    await config.restConnectionId.fill(PLACEHOLDER_CONNECTION_ID);
    // The whole group is ~2000px tall, most of it settings this screenshot is
    // not about. Clip to Enabled → Connection ID, which is what step 2 of the
    // quick start walks through.
    await shootRange(
      page,
      page.locator('#row_tax_taxcloud_enabled'),
      page.locator('#row_tax_taxcloud_rest_connection_id'),
      'quickstart-taxcloud-settings.png',
    );
  });

  test('verify credentials', async ({ page }) => {
    const config = await openTaxConfig(page);
    // Uses whatever credentials the store was installed with, so the result is
    // a genuine success — but only the button and its result line are in frame,
    // never the credential fields above them.
    await config.testConnection();
    await shoot(
      page.locator('#row_tax_taxcloud_test_connection'),
      'quickstart-verify-credentials.png',
    );
  });

  test('exemption settings', async ({ page }) => {
    const config = await openTaxConfig(page);
    await config.setExemptions(true);
    await expect(page.locator('#tax_taxcloud_company_name')).toBeVisible({ timeout: 10_000 });
    await page.locator('#tax_taxcloud_company_name').fill('Example Retail LLC');
    await shootRange(
      page,
      page.locator('#row_tax_taxcloud_exemptions_enabled'),
      page.locator('#row_tax_taxcloud_company_name'),
      'exemptions-settings.png',
    );
  });

  test('certificates permission', async ({ page }) => {
    await page.goto('/admin/admin/user_role/');
    await page.locator('tr:has-text("Administrators")').first().click();
    await page.locator('.admin__page-nav').first().waitFor({ timeout: 30_000 });
    await page.locator('a:has-text("Role Resources")').first().click();

    // Administrators has Resource Access = All, which hides the tree entirely.
    // Switching the select to Custom reveals it; nothing is saved, so the role
    // is unchanged.
    const access = page.locator('#all_resource, select[name="all"]').first();
    await access.waitFor({ timeout: 30_000 });
    await access.selectOption({ label: 'Custom' });

    const label = page.getByText('TaxCloud Exemption Certificates', { exact: false }).last();
    await label.waitFor({ timeout: 30_000 });
    // Two levels up: the Customers branch, so the reader can see where in the
    // tree to look rather than a single disembodied line.
    await shoot(label.locator('xpath=ancestor::li[2]'), 'exemptions-acl.png');
  });

  test('cache management', async ({ page }) => {
    await page.goto('/admin/admin/cache/');
    const row = page.locator('tr:has-text("TaxCloud")').first();
    await row.waitFor({ timeout: 30_000 });
    await shoot(row, 'cache-management-taxcloud.png');
  });

  test('product grid TIC column', async ({ page }) => {
    await page.goto('/admin/catalog/product/');
    await page.locator('.admin__data-grid-loading-mask').waitFor({ state: 'hidden', timeout: 40_000 })
      .catch(() => {});
    const grid = visibleGrid(page);
    await grid.waitFor({ timeout: 40_000 });
    await shoot(grid, 'assigning-tics-grid.png');
  });

  test('bulk update attributes', async ({ page }) => {
    await page.goto('/admin/catalog/product/');
    await page.locator('.admin__data-grid-loading-mask')
      .waitFor({ state: 'hidden', timeout: 40_000 }).catch(() => {});
    const grid = visibleGrid(page);
    await grid.waitFor({ timeout: 40_000 });

    // One ticked row is enough: the screenshot is of the Update attributes
    // screen, not of the selection. Ticking re-renders the grid, so wait for it
    // to settle before reaching for the Actions menu.
    await grid.locator('tbody tr td.data-grid-checkbox-cell input').first().check();
    await page.locator('.admin__data-grid-loading-mask')
      .waitFor({ state: 'hidden', timeout: 30_000 }).catch(() => {});
    await page.waitForTimeout(500);
    await page.locator('.action-select', { hasText: 'Actions' }).first().click();
    await page.locator('a:has-text("Update attributes"), span:has-text("Update attributes")')
      .first().click();

    const row = page.locator('.admin__field').filter({ hasText: 'Taxcloud TIC' }).first();
    await row.waitFor({ timeout: 60_000 });
    await shoot(row, 'assigning-tics-bulk.png');
  });

  test('product TIC field', async ({ page }) => {
    // test-variant-red carries a TIC (20010, Clothing), so the field is shown
    // resolved to its description rather than empty.
    await openGridRow(page, '/admin/catalog/product/', 'test-variant-red');
    const row = page.locator('.admin__field').filter({ hasText: 'Taxcloud TIC' }).first();
    await row.waitFor({ timeout: 60_000 });
    await shoot(row, 'assigning-tics-product.png');
  });

  test('category TIC field', async ({ page }) => {
    await page.goto('/admin/catalog/category/');
    await page.getByText('Test Category', { exact: false }).first().click({ timeout: 40_000 });
    await expect(page.locator('[name="name"]')).toHaveValue('Test Category', { timeout: 40_000 });

    // The TaxCloud fieldset is a collapsed accordion on load.
    const section = page.locator('.fieldset-wrapper').filter({ hasText: 'TaxCloud' }).first();
    await section.waitFor({ timeout: 30_000 });
    const field = page.locator('[name="taxcloud_tic"]');
    if (!(await field.isVisible().catch(() => false))) {
      await section.locator('.fieldset-wrapper-title, [data-role="title"]').first().click();
      await expect(field).toBeVisible({ timeout: 20_000 });
    }
    await shoot(section, 'assigning-tics-category.png');
  });

  test('customer certificate panel', async ({ page }) => {
    await openCertificateTab(page);
    await page.waitForTimeout(2000); // let the listing finish rendering
    await shoot(page.locator('[data-taxcloud-certificates]'), 'certificates-admin-panel.png');
  });

  test('create certificate form', async ({ page }) => {
    await openCertificateTab(page);
    await page.locator('button:has-text("Add Certificate")').first().click();
    const form = page.locator('#tc_cert_form');
    await expect(form).toBeVisible({ timeout: 30_000 });

    // Filled in, so the screenshot shows what a completed certificate looks
    // like rather than an empty form. Nothing is submitted.
    await page.locator('#tc_cert_states').selectOption(['TX']).catch(() => {});
    await page.locator('#tc_cert_firstname').fill('Jordan');
    await page.locator('[data-field="lastName"]').first().fill('Reyes');
    await page.locator('#tc_cert_address1').fill('120 Congress Ave');
    await page.locator('#tc_cert_city').fill('Austin');
    await page.locator('#tc_cert_state').selectOption({ label: 'Texas' }).catch(() => {});
    await page.locator('#tc_cert_zip').fill('78701');
    await page.locator('#tc_cert_businesstype').selectOption({ index: 1 }).catch(() => {});
    await page.locator('#tc_cert_reason').selectOption({ index: 1 }).catch(() => {});
    await shoot(form, 'certificates-add-form.png');
  });
});

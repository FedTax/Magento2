import { test, expect, type Page } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';

/**
 * TIC autocomplete, in the two places it is most likely to break silently.
 *
 * The product form is the riskiest mount: its fields are generated from EAV
 * metadata at runtime, so the component is attached by a form modifier rather
 * than declared in XML. When that goes wrong the field still renders — as a
 * plain text box — and nothing errors. The configuration fields are the second
 * riskiest: they host the control through a frontend_model block because the
 * configuration form has no data provider, which is the one mount with no
 * framework support behind it.
 *
 * Both failure modes look like success to every other suite, which is why they
 * are checked here rather than left to unit and integration coverage.
 *
 * Runs against the live TaxCloud API with the install's seeded credentials, so
 * these also prove the endpoint authenticates.
 */

/** A TIC that exists in TaxCloud's catalogue, and its description. */
const KNOWN = { query: 'candy', code: '40010', label: 'Candy' };

/** Not in the catalogue — must be kept, not corrected. */
const UNKNOWN_CODE = '45999';

/**
 * Type into a TIC field and wait for the dropdown to settle. The component
 * debounces, so a fixed wait after the last keystroke is what a user
 * experiences too.
 */
async function search(page: Page, input: string, query: string): Promise<void> {
  const field = page.locator(input);
  await field.click();
  await field.fill('');
  await field.type(query, { delay: 40 });
  await page.waitForTimeout(2500);
}

test.describe('TIC search', () => {
  test('product form: search, select, and keep an unknown code', async ({ page }) => {
    test.setTimeout(180_000);

    await new AdminLoginPage(page).login();
    await page.goto('/admin/catalog/product/edit/id/1/');

    const ticField = page.locator('[data-index="taxcloud_tic"]');
    await expect(ticField).toBeVisible({ timeout: 60_000 });

    // The modifier attached the component: a plain text box would have none of
    // these classes, and would still look perfectly normal.
    const input = ticField.locator('.taxcloud-tic-input');
    await expect(
      input,
      'the product TIC field is not the autocomplete — the form modifier did not attach'
    ).toHaveCount(1);

    await search(page, '[data-index="taxcloud_tic"] .taxcloud-tic-input', KNOWN.query);

    const suggestions = ticField.locator('.taxcloud-tic-suggestion');
    await expect(suggestions.first()).toBeVisible();
    await expect(suggestions.first().locator('.taxcloud-tic-code')).toHaveText(KNOWN.code);

    // The spinner must clear once results land, or the field looks permanently
    // busy. (Catching it while visible would be a race against a fast API, so
    // this asserts the state that actually matters.)
    await expect(ticField.locator('.taxcloud-tic-spinner')).toHaveCount(0);

    await suggestions.first().click();
    await expect(input).toHaveValue(KNOWN.code);
    await expect(ticField.locator('.taxcloud-tic-note._resolved')).toContainText(KNOWN.label);

    // The field stays free-text: an unrecognised code is reported and kept,
    // never cleared or corrected.
    await search(page, '[data-index="taxcloud_tic"] .taxcloud-tic-input', UNKNOWN_CODE);
    await input.blur();
    await expect(ticField.locator('.taxcloud-tic-note._notfound')).toBeVisible();
    await expect(input).toHaveValue(UNKNOWN_CODE);
  });

  test('configuration: both TIC fields explain their saved code', async ({ page }) => {
    test.setTimeout(180_000);

    await new AdminLoginPage(page).login();
    await new TaxConfigPage(page).open();

    // Both settings host the same control through the frontend_model block.
    for (const id of ['#tax_taxcloud_default_tic', '#tax_taxcloud_shipping_tic']) {
      const input = page.locator(id);
      await expect(input, `${id} is not the autocomplete control`).toHaveClass(/taxcloud-tic-input/);

      // The config form posts by field name; the control must not have taken
      // that over, or the setting silently stops saving.
      await expect(input).toHaveAttribute('name', /groups\[taxcloud\]\[fields\]\[\w+\]\[value\]/);

      // A saved code resolves to its description without any interaction.
      const note = page.locator(`${id} ~ .taxcloud-tic-note, ${id}`).locator('..')
        .locator('.taxcloud-tic-note._resolved');
      await expect(note).toBeVisible({ timeout: 30_000 });
      await expect(note).not.toBeEmpty();
    }

    // Searching works here too, over the same backend the store transacts on.
    await search(page, '#tax_taxcloud_shipping_tic', 'handling');
    const suggestions = page.locator('#tax_taxcloud_shipping_tic')
      .locator('..').locator('.taxcloud-tic-suggestion');
    await expect(suggestions.first()).toBeVisible();
    await expect(suggestions.first().locator('.taxcloud-tic-code')).toHaveText(/^\d+$/);

    // A suggestion has to be *readable*, not merely present. This shipped
    // broken on the Spectrum admin theme: the label had no explicit colour, so
    // it inherited one that painted it invisible on the configuration form
    // while every other part of the row still showed. Asserting the element
    // exists would have passed the whole time.
    const label = suggestions.first().locator('.taxcloud-tic-label');
    await expect(label).toBeVisible();
    await expect(label).not.toBeEmpty();
    await expect
      .poll(async () => label.evaluate((el) => getComputedStyle(el).color))
      .not.toMatch(/rgba?\(\s*255,\s*255,\s*255|rgba\([^)]*,\s*0\s*\)|transparent/);
  });
});

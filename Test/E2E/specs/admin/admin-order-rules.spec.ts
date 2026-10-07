import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { OrderRulesPage } from '../../pages/admin/OrderRulesPage';

/**
 * Order processing rules screen: create, validate, reorder by drag, edit and
 * delete — through the real admin, against the live install.
 *
 * Proves over the unit tests: the menu route, ACL, UI-component form and its
 * Skip switcher, the repository validation surfacing as a form error, and the
 * drag-reorder round trip (jQuery UI sortable → saveOrder endpoint → stored
 * order shown after a reload).
 *
 * The rules are deleted in `finally`, because every later checkout in the run
 * would otherwise be decided by them.
 */
const RULE_A = 'E2E rule A';
const RULE_B = 'E2E rule B';
const RULE_A_RENAMED = 'E2E rule A (renamed)';

test.describe('TaxCloud order rules', () => {
  test('create, validate, reorder, edit and delete rules', async ({ page }) => {
    test.setTimeout(240_000);

    await new AdminLoginPage(page).login();
    const rules = new OrderRulesPage(page);

    // A retried attempt starts from what the failed one left behind.
    for (const name of [RULE_A, RULE_B, RULE_A_RENAMED]) {
      await rules.delete(name);
    }

    try {
      // An empty list explains what happens with no rules.
      await rules.open();
      await expect(page.locator('.taxcloud-order-rules__default'))
        .toContainText('Orders that match no rule follow each store view');

      // A: a prefix rule (calculate only) — appended at the end.
      await rules.create({ name: RULE_A, action: 'calculate_only', orderPrefixes: ['E2E-NEVER-'] });
      await expect(rules.row(RULE_A)).toContainText('Order number starts with: E2E-NEVER-');
      await expect(rules.row(RULE_A)).toContainText('Calculate only');

      // B: choosing Skip shows the checkout warning and disables the prefix field.
      await rules.openNew();
      await rules.fill({ name: RULE_B, action: 'skip', paymentMethods: ['checkmo'] });
      await expect(rules.skipWarning).toBeVisible();
      await expect(rules.prefixField).toBeDisabled();
      await page.locator('select[name="action"]').selectOption('calculate_only');
      await expect(rules.skipWarning).toBeHidden();
      await expect(rules.prefixField).toBeEnabled();

      // Fill the prefix while it is enabled, then switch to Skip and save: the
      // disabled field keeps its value in the form data. Either the server
      // refuses Skip + prefix (error), or the value was not submitted (saved)
      // — never a saved skip rule that carries a prefix.
      await rules.prefixField.fill('E2E-NEVER-');
      await page.locator('select[name="action"]').selectOption('skip');
      await rules.save();
      const outcome = await Promise.race([
        rules.successMessage.waitFor({ timeout: 30_000 }).then(() => 'saved'),
        rules.errorMessage.waitFor({ timeout: 30_000 }).then(() => 'refused'),
      ]);
      if (outcome === 'refused') {
        await expect(rules.errorMessage).toContainText('cannot filter on the order number');
        await page.locator('select[name="action"]').selectOption('calculate_only');
        await rules.prefixField.fill('');
        await page.locator('select[name="action"]').selectOption('skip');
        await rules.save();
        await expect(rules.successMessage).toContainText('The order rule has been saved', { timeout: 30_000 });
      }
      await rules.open();
      await expect(rules.row(RULE_B)).toContainText('Skip TaxCloud');
      await expect(rules.row(RULE_B)).not.toContainText('Order number starts with');

      // Order: A then B. Drag B above A; the order survives a reload.
      expect((await rules.names()).filter((n) => n.startsWith('E2E rule'))).toEqual([RULE_A, RULE_B]);
      await rules.drag(RULE_B, RULE_A);
      await rules.open();
      expect((await rules.names()).filter((n) => n.startsWith('E2E rule'))).toEqual([RULE_B, RULE_A]);

      // Edit A: rename, deactivate via the toggle, save.
      await rules.openEdit(RULE_A);
      await page.locator('input[name="name"]').fill(RULE_A_RENAMED);
      await page.locator('.admin__actions-switch-label').first().click();
      await rules.save();
      await expect(rules.successMessage).toContainText('The order rule has been saved', { timeout: 30_000 });
      await expect(rules.row(RULE_A_RENAMED)).toHaveClass(/taxcloud-order-rules__inactive/);
      await expect(rules.row(RULE_A_RENAMED)).toContainText('No');
    } finally {
      await rules.delete(RULE_B);
      await rules.delete(RULE_A);
      await rules.delete(RULE_A_RENAMED);
    }

    await rules.open();
    await expect(rules.row(RULE_B)).toHaveCount(0);
    await expect(rules.row(RULE_A_RENAMED)).toHaveCount(0);
  });
});

import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { CustomerCertificatesPage } from '../../pages/admin/CustomerCertificatesPage';

/**
 * The add-certificate form, at the two points it can mislead an administrator.
 *
 * The form previously carried Magento's `required` classes, which draw the
 * asterisk and nothing else: there was no form element, no validation rules and
 * nothing calling mage/validation, so every missing field cost a server round
 * trip and came back as one message for the whole form. And a server rejection
 * rendered as inline text at the top of a long panel — off-screen from the Save
 * button that triggered it, which reads as a save that silently did nothing.
 *
 * Both are invisible to unit tests: CertificateFormReader's rules are correct
 * either way. What matters here is that the browser stops the request, and that
 * a rejection is put somewhere the person who caused it will see it.
 */
const SEEDED_CUSTOMER = 2;

test.describe('adding a certificate from the admin', () => {
  test('an incomplete form is refused without asking the server', async ({ page }) => {
    test.setTimeout(240_000);
    await new AdminLoginPage(page).login();

    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    const before = panel.calls.length;
    await page.locator('[data-role="show-add"]').click();
    await page.locator('[data-role="save"]').click();
    await page.waitForTimeout(3000);

    const errors = (await page.locator('#tc_cert_form .mage-error').allInnerTexts())
      .filter((t) => /required field/i.test(t));
    expect(
      errors.length,
      'every required field must report itself, not just the first one the server happens to reach',
    ).toBeGreaterThanOrEqual(7);

    expect(
      panel.calls.slice(before).filter((c) => c.startsWith('POST')),
      'nothing may be sent while the form is incomplete — the rules are known in the browser',
    ).toEqual([]);
  });

  test('a complete form satisfies validation', async ({ page }) => {
    test.setTimeout(240_000);
    await new AdminLoginPage(page).login();

    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    await page.locator('[data-role="show-add"]').click();
    await page.selectOption('#tc_cert_states', ['TX']);
    await page.fill('#tc_cert_firstname', 'Validation');
    await page.fill('[data-field="lastName"]', 'Probe');
    await page.fill('#tc_cert_address1', '1401 Lavaca St');
    await page.fill('#tc_cert_city', 'Austin');
    await page.selectOption('#tc_cert_state', 'TX');
    await page.fill('#tc_cert_zip', '78701');

    // Deliberately does NOT save: this asserts the gate opens, without spending
    // a real certificate on TaxCloud to prove it. Creation itself is covered by
    // certificate-attach.spec.ts.
    const valid = await page.evaluate(() => (window as any).jQuery('#tc_cert_form').valid());
    expect(valid, 'a complete form must pass validation').toBe(true);

    const remaining = (await page.locator('#tc_cert_form .mage-error').allInnerTexts())
      .filter((t) => /required field/i.test(t));
    expect(remaining, 'no field may still be reporting itself as missing').toEqual([]);
  });

  test('a rejection from TaxCloud is put in front of the administrator', async ({ page }) => {
    test.setTimeout(240_000);
    await new AdminLoginPage(page).login();

    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    await page.locator('[data-role="show-add"]').click();

    // Everything valid client-side except the states, which the server checks
    // first — a rejection the browser cannot anticipate, which is the case the
    // modal exists for. Selecting none leaves the field empty, so client
    // validation catches it; instead submit through the endpoint directly.
    await page.selectOption('#tc_cert_states', ['TX']);
    await page.fill('#tc_cert_firstname', 'Rejected');
    await page.fill('[data-field="lastName"]', 'Probe');
    await page.fill('#tc_cert_address1', '1401 Lavaca St');
    await page.fill('#tc_cert_city', 'Austin');
    await page.selectOption('#tc_cert_state', 'TX');
    await page.fill('#tc_cert_zip', '78701');
    // Longer than TaxCloud accepts: the server rejects, the browser cannot know.
    await page.fill('#tc_cert_reasondesc', 'x'.repeat(20));
    await page.evaluate(() => {
      const field = document.querySelector('#tc_cert_reasondesc') as HTMLInputElement;
      field.removeAttribute('maxlength');
      field.value = 'this description is far too long for TaxCloud';
    });

    await page.locator('[data-role="save"]').click();

    const modal = page
      .locator('.modal-popup')
      .filter({ hasText: /certificate/i })
      .first();

    await expect(
      modal,
      'a rejection must be put in front of the administrator, not left inline above the fold',
    ).toBeVisible({ timeout: 60_000 });

    const text = (await modal.locator('.modal-content').innerText()).trim();
    expect(text.length, 'the modal must carry the reason, not just announce failure').toBeGreaterThan(0);
  });
});

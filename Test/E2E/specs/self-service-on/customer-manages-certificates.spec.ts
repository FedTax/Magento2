import { test, expect, type Page } from '@playwright/test';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage } from '../../pages/storefront/CheckoutPage';
import { loginAsExemptCustomer, loginAsTrustedCustomer } from '../../fixtures/auth';

/**
 * Certificate self-service, end to end.
 *
 * The self-service-on-setup project nominates the Wholesale group. The trusted
 * customer is in it and holds no certificate of its own; the exempt customer is
 * in General and holds the seeded TX certificate.
 *
 * What only a browser can show: that the controls render for the right
 * customer and not the other, that the add form submits what the endpoint
 * accepts (attestation included), and that the result is visible where it
 * matters — the checkout total. Every refusal the endpoints make is covered by
 * StorefrontCertificateControllersTest and CustomerSelfServiceTest.
 *
 * Same product and address as logged-in-checkout.spec.ts: $10 + $5 shipping to
 * Austin TX is $1.24 of tax when taxed, none when exempt.
 */
const TAXED_GRAND_TOTAL = '$16.24';
const EXEMPT_GRAND_TOTAL = '$15.00';

async function openCertificates(page: Page): Promise<void> {
  await page.goto('/taxcloud/certificate/');
  await expect(page.locator('[data-taxcloud-certificates]')).toBeVisible();
  // The list is read live from TaxCloud after the page loads. Either rows or
  // the "no certificates" notice means the read finished.
  await expect(
    page.locator('[data-role="certificate-rows"] tr, [data-role="status"] .message'),
  ).not.toHaveCount(0, { timeout: 60_000 });
  await expect(page.locator('[data-role="status"]')).not.toContainText(/could not/i);
}

/**
 * Remove every certificate the customer holds, so the spec starts from none.
 * Each run files under a run-unique identity, so this normally finds nothing —
 * it matters only when a run is retried after getting part way.
 */
async function removeAll(page: Page): Promise<void> {
  const remove = page.locator('[data-role="certificate-rows"] [data-delete]');

  for (let guard = 0; guard < 10 && (await remove.count()) > 0; guard++) {
    await remove.first().click();
    await expect(remove).toHaveCount(Math.max(0, (await remove.count()) - 1), { timeout: 60_000 });
  }

  await expect(remove).toHaveCount(0);
}

async function checkoutGrandTotal(page: Page, expectTax: boolean): Promise<string> {
  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await checkout.emptyCart();
  await product.open('test-product');
  await product.addToCart();
  await checkout.openAsCustomer();
  await checkout.selectFlatRateAndContinue({ expectTax });

  return (await checkout.grandTotal.innerText()).trim();
}

test.describe('customer certificate self-service', () => {
  test.beforeEach(async ({ page }) => {
    // Remove and Stop using ask first; the specs mean yes.
    page.on('dialog', (dialog) => dialog.accept());
  });

  test('a customer outside the nominated groups sees the certificate in use but cannot change it', async ({
    page,
  }) => {
    test.setTimeout(240_000);
    await loginAsExemptCustomer(page);
    await openCertificates(page);

    await expect(
      page.locator('[data-role="certificate-rows"] td', { hasText: 'In use' }),
      'every customer can tell which certificate applies',
    ).toHaveCount(1);
    await expect(page.locator('[data-role="show-add"]')).toHaveCount(0);
    await expect(page.locator('[data-role="add-form"]')).toHaveCount(0);
    await expect(page.locator('[data-role="refresh"]')).toHaveCount(0);
    await expect(page.locator('[data-attach]')).toHaveCount(0);
  });

  test('a nominated customer adds a certificate, is exempt, and is taxed again once it is removed', async ({
    page,
  }) => {
    test.setTimeout(420_000);
    await loginAsTrustedCustomer(page);
    await openCertificates(page);
    await removeAll(page);

    await test.step('taxed with no certificate', async () => {
      expect(await checkoutGrandTotal(page, true)).toBe(TAXED_GRAND_TOTAL);
    });

    await test.step('add a certificate covering TX', async () => {
      await openCertificates(page);
      await page.locator('[data-role="show-add"]').click();

      const form = page.locator('[data-role="add-form"]');
      await expect(form).toBeVisible();
      // Started from the default billing address the seed gave this customer.
      await expect(form.locator('#tc_cert_city')).toHaveValue('Austin');
      await expect(form.locator('#tc_cert_state')).toHaveValue('TX');

      await form.locator('#tc_cert_states').selectOption(['TX']);
      await form.locator('#tc_cert_businesstype').selectOption({ label: 'Wholesale Trade' });
      await form.locator('#tc_cert_reason').selectOption({ label: 'Resale' });

      // Without the attestation the browser refuses first; the server refusal
      // is covered by the controller tests.
      await page.locator('[data-role="save"]').click();
      await expect(form.locator('#tc_cert_attestation-error, .mage-error')).not.toHaveCount(0);

      await form.locator('#tc_cert_attestation').check();
      await page.locator('[data-role="save"]').click();

      await expect(page.locator('[data-role="status"]')).toContainText('now in use', { timeout: 90_000 });
      await expect(page.locator('[data-role="certificate-rows"] tr')).toHaveCount(1);
      await expect(page.locator('[data-role="certificate-rows"] td', { hasText: 'In use' })).toHaveCount(1);
    });

    await test.step('exempt at checkout', async () => {
      expect(await checkoutGrandTotal(page, false)).toBe(EXEMPT_GRAND_TOTAL);
    });

    await test.step('stop using it, then use it again', async () => {
      await openCertificates(page);
      await page.locator('[data-role="certificate-rows"] [data-attach=""]').click();
      await expect(page.locator('[data-role="certificate-rows"] [data-attach]:not([data-attach=""])')).toHaveCount(
        1,
        { timeout: 60_000 },
      );
      await expect(page.locator('[data-role="certificate-rows"] td', { hasText: 'In use' })).toHaveCount(0);

      await page.locator('[data-role="certificate-rows"] [data-attach]:not([data-attach=""])').click();
      await expect(page.locator('[data-role="certificate-rows"] td', { hasText: 'In use' })).toHaveCount(1, {
        timeout: 60_000,
      });
    });

    await test.step('remove the certificate in use', async () => {
      await removeAll(page);
    });

    await test.step('taxed again', async () => {
      expect(await checkoutGrandTotal(page, true)).toBe(TAXED_GRAND_TOTAL);
    });
  });
});

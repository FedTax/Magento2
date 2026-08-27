import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { CustomerCertificatesPage } from '../../pages/admin/CustomerCertificatesPage';
import { loginAsExemptCustomer } from '../../fixtures/auth';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage } from '../../pages/storefront/CheckoutPage';

/**
 * A customer in an exempt group is exempted without choosing anything.
 *
 * This is the feature the Exempt Customer Groups setting advertises, and until
 * now it had no coverage outside mocks: the seed nominates no groups and grants
 * exemption by ATTACHING a certificate, so every green exemption test in the
 * suite actually proved the attachment path. Group auto-apply could have been
 * broken in production and nothing here would have failed.
 *
 * So the attachment is deliberately cleared first. With nothing attached and no
 * choice made at checkout, an exemption can only come from the group — which is
 * exactly what is under test.
 *
 * Runs in the exemptions-on project, which switches the setting on and restores
 * it in a teardown project afterwards.
 */
const SEEDED_CUSTOMER = 2;

/*
 * The attachment this spec clears is restored by the exemptions-on TEARDOWN
 * project, not here. Restoring inline meant a second admin login inside the
 * longest spec in the suite, which is where it kept going flaky — and a hook
 * cannot restore anything when the run is killed, while a teardown project can.
 */

test('a customer in an exempt group is exempted with nothing attached and nothing chosen', async ({
  page,
}) => {
  test.setTimeout(300_000);

  await new AdminLoginPage(page).login();
  const panel = new CustomerCertificatesPage(page);
  await panel.openCustomer(SEEDED_CUSTOMER);
  await panel.openTab();

  const ids = await panel.certificateIds();
  expect(ids, 'the seeded customer must hold a certificate for the group to apply').not.toHaveLength(0);

  if ((await panel.attachedId()) !== '') {
    await panel.clearAttachment();
  }

  expect(
    await panel.attachedId(),
    'nothing may be attached, or this would prove the attachment path again',
  ).toBe('');

  await loginAsExemptCustomer(page);

  const product = new ProductPage(page);
  const checkout = new CheckoutPage(page);

  await checkout.emptyCart();
  await product.open('test-product');
  await product.addToCart();
  await checkout.openAsCustomer();
  await checkout.selectFlatRateAndContinue({ expectTax: false });

  await expect(
    checkout.grandTotal,
    'the group alone must exempt the order — no attachment, no choice at checkout',
  ).toHaveText('$15.00');
});

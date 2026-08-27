import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { CustomerCertificatesPage } from '../../pages/admin/CustomerCertificatesPage';

/**
 * Attaching a certificate to a customer, through the interface.
 *
 * Holding a certificate and having it apply are different states, and only the
 * attachment decides which. Until this control existed an administrator could
 * create a certificate for a customer and have no way to make it apply, short
 * of nominating an exempt customer group in global configuration — the gap that
 * produced "I added a TX certificate, checked out to TX, and was still taxed".
 *
 * This spec creates real certificates against TaxCloud, so it cleans up at both
 * ends: an afterEach does not run when a test is killed mid-flight, and a
 * leftover certificate on the seeded customer would change what every other
 * exemption test sees.
 */
const SEEDED_CUSTOMER = 2;
const MARKER = 'Probe';

test.describe.configure({ mode: 'serial' });

test.describe('attaching a certificate', () => {
  let originalAttachment = '';

  test.beforeEach(async ({ page }) => {
    test.setTimeout(300_000);
    await new AdminLoginPage(page).login();
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    // Establish the precondition rather than assume it. These tests change the
    // attachment on a customer the rest of the suite also uses, and a killed run
    // leaves it cleared — a baseline merely *read* here would then be empty, the
    // restore a no-op, and the drift permanent. Healing it makes the spec safe
    // to run repeatedly, in any order, after any failure.
    await panel.deleteCertificatesNamed(MARKER);

    const ids = await panel.certificateIds();
    expect(ids, 'the seeded customer must hold a certificate to attach').not.toHaveLength(0);

    if ((await panel.attachedId()) === '') {
      await panel.attach(ids[0]);
    }

    originalAttachment = await panel.attachedId();
    expect(originalAttachment).not.toBe('');
  });

  test.afterEach(async ({ page }) => {
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();
    await panel.deleteCertificatesNamed(MARKER);

    if (originalAttachment && (await panel.attachedId()) !== originalAttachment) {
      await panel.attach(originalAttachment);
    }

    // Assert the handover rather than hope for it. Everything downstream that
    // checks exemption reads this customer, and a spec that quietly leaves the
    // attachment cleared makes the NEXT spec fail — for reasons that look
    // nothing like the cause.
    expect(
      await panel.attachedId(),
      'this spec must hand the seeded customer back exactly as it found them',
    ).toBe(originalAttachment);
    expect(await panel.rowFor(MARKER).count(), 'no certificate this spec created may survive it')
      .toBe(0);
  });

  test('a certificate can be put in use and taken out of use', async ({ page }) => {
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    await panel.clearAttachment();
    expect(
      await panel.attachedId(),
      'clearing must leave nothing in use — the customer then falls back to whatever ' +
        'the store does automatically, which for an empty group list is nothing',
    ).toBe('');

    await panel.attach(originalAttachment);
    expect(await panel.attachedId()).toBe(originalAttachment);
  });

  test('creating a certificate attaches it when the customer has none', async ({ page }) => {
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    await panel.clearAttachment();
    expect(await panel.attachedId()).toBe('');

    const created = await panel.createCertificate(MARKER);

    expect(
      await panel.attachedId(),
      'an administrator who adds a certificate for a customer has already said what ' +
        'they mean; a second click on a control they have not noticed is the gap this closes',
    ).toBe(created);
  });

  test('creating a second certificate does not displace the attached one', async ({ page }) => {
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    expect(await panel.attachedId()).toBe(originalAttachment);

    const created = await panel.createCertificate(MARKER);

    expect(
      await panel.attachedId(),
      'silently re-filing a customer against a different certificate is the opposite ' +
        'of what adding a second one usually means',
    ).toBe(originalAttachment);
    expect(created).not.toBe(originalAttachment);
  });
});

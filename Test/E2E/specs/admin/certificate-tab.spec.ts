import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { CustomerCertificatesPage } from '../../pages/admin/CustomerCertificatesPage';

/**
 * The certificate panel on the customer page, as a browser sees it.
 *
 * This spec exists because of a failure mode nothing else in the suite can
 * catch: the panel renders correctly — heading, table, buttons, no console
 * error — and never talks to Magento at all. It has happened twice, from two
 * unrelated causes (an x-magento-init script inside knockout-injected tab
 * content, and a stale DI cache after a constructor changed). Unit and
 * integration tests both stay green through it, because the decision logic they
 * cover is never reached.
 *
 * So the assertions here are deliberately about behaviour, not markup: a
 * request was made, exactly one panel is live, the listing rendered.
 *
 * The seeded customer 2 holds one certificate, attached — see the seed.
 */
const SEEDED_CUSTOMER = 2;

test.describe('admin certificate panel', () => {
  test('renders as a customer tab after Account Information', async ({ page }) => {
    test.setTimeout(240_000);
    await new AdminLoginPage(page).login();

    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);

    const tabs = await panel.tabLabels();
    const index = tabs.findIndex((t) => /TaxCloud/i.test(t));

    expect(index, `no TaxCloud tab among: ${tabs.join(' > ')}`).toBeGreaterThan(-1);
    expect(
      tabs[index - 1],
      'the panel belongs with the other per-customer settings, not at the top of the page',
    ).toBe('Account Information');
  });

  test('asks Magento for the certificates when the tab is opened', async ({ page }) => {
    test.setTimeout(240_000);
    const consoleErrors: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') consoleErrors.push(m.text());
    });

    await new AdminLoginPage(page).login();
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);

    // openTab() fails if no request is made — the regression itself.
    await panel.openTab();

    expect(
      panel.calls.some((c) => c.startsWith('GET')),
      'the listing must be fetched, not assumed',
    ).toBe(true);

    // Two live panels would each issue their own writes against TaxCloud.
    await expect(panel.panel).toHaveCount(1);
    expect(consoleErrors, 'the panel must initialise without errors').toEqual([]);
  });

  test('shows the seeded certificate and which one is in use', async ({ page }) => {
    test.setTimeout(240_000);
    await new AdminLoginPage(page).login();
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    await expect(
      panel.rows,
      'the seeded exempt customer holds a certificate; an empty table here means ' +
        'either the listing broke or the identity resolved to the wrong value',
    ).not.toHaveCount(0);

    // A failed read must never render as "this customer is not exempt".
    await expect(panel.status).not.toHaveText(/Could not|failed|expired/i);

    expect(
      await panel.attachedId(),
      'the seed attaches a certificate, so exactly one row must be marked In use',
    ).not.toBe('');
  });

  test('refresh re-reads the list and masks only the table', async ({ page }) => {
    test.setTimeout(240_000);
    await new AdminLoginPage(page).login();
    const panel = new CustomerCertificatesPage(page);
    await panel.openCustomer(SEEDED_CUSTOMER);
    await panel.openTab();

    const before = panel.calls.length;
    await page.locator('[data-role="refresh"]').click();

    // The mask belongs to the table, not the page: refreshing reloads this list
    // and nothing else, and a full-page mask says otherwise.
    const spinner = page.locator('[data-role="table-region"] [data-role="spinner"]');
    await expect(spinner).toBeVisible({ timeout: 30_000 });

    const geometry = await page.evaluate(() => {
      const region = document.querySelector('[data-role="table-region"]') as HTMLElement;
      const mask = region.querySelector('[data-role="spinner"]') as HTMLElement;
      const r = region.getBoundingClientRect();
      const m = mask.getBoundingClientRect();
      return {
        sameWidth: Math.abs(r.width - m.width) < 2,
        alignedTop: Math.abs(r.top - m.top) < 4,
        position: getComputedStyle(mask).position,
      };
    });
    expect(geometry.position, 'a fixed mask covers the viewport, not the table').toBe('absolute');
    expect(geometry.sameWidth && geometry.alignedTop, 'the mask must span the table').toBe(true);

    await expect(spinner).toBeHidden({ timeout: 90_000 });
    expect(
      panel.calls.length,
      'refresh must discard the cache and read again, not redraw what it had',
    ).toBeGreaterThan(before + 1);
  });
});

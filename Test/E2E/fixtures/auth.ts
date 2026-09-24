/**
 * Authentication helpers for E2E.
 *
 * The seeded storefront customers (scripts/seed-test-data.php, sections 4h/4j),
 * both with password Test1234! and both carrying a default Austin TX shipping
 * address:
 *
 *   customer@example.com          - plain customer, taxed normally
 *   exempt-customer@example.com   - holds a TaxCloud exemption certificate
 *                                   covering TX, so its orders come out exempt
 *   trusted-customer@example.com  - Wholesale group, no certificate; the
 *                                   self-service pass nominates Wholesale
 *
 * The pair is what makes an exemption assertion meaningful: the same cart to the
 * same address differs only in who is signed in, so a zero tax line can be read
 * as "the exemption applied" rather than "tax is broken".
 *
 * Admin login lives in pages/admin/AdminLoginPage.ts; this module is the
 * storefront side.
 */
import { type Page, expect } from '@playwright/test';

export const CUSTOMER_PASSWORD = 'Test1234!';
export const PLAIN_CUSTOMER_EMAIL = 'customer@example.com';
export const EXEMPT_CUSTOMER_EMAIL = 'exempt-customer@example.com';
/** Wholesale-group customer with no certificate, nominated by the self-service pass. */
export const TRUSTED_CUSTOMER_EMAIL = 'trusted-customer@example.com';

/**
 * Log a seeded customer in through the storefront login form and wait until the
 * account dashboard confirms the session.
 *
 * Waiting on the dashboard rather than the POST matters: Magento answers a
 * failed login with a 200 and an error banner on the same URL, so a spec that
 * only awaited navigation would sail past a bad login and fail later somewhere
 * far less obvious.
 */
export async function loginAsCustomer(
  page: Page,
  email: string,
  password: string = CUSTOMER_PASSWORD,
): Promise<void> {
  // Attempted twice. Magento invalidates a customer's storefront session when
  // their record is saved — the CustomerNotification plugin forces a reload on
  // the next request and can drop the session — so a suite that edits a
  // customer from the admin (attaching a certificate, say) intermittently lands
  // a later storefront login on a session that is discarded underneath it. The
  // symptom is a checkout page rendering "Sign In", far from the cause.
  //
  // One retry, not a longer timeout: the first attempt does not time out, it
  // completes and is then thrown away.
  try {
    await attemptLogin(page, email, password);

    return;
  } catch (firstAttempt) {
    await page.context().clearCookies();
  }

  await attemptLogin(page, email, password);
}

async function attemptLogin(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/customer/account/login/');

  // Scoped to the login BLOCK, not to #login-form, and not to the page.
  //
  // Luma's authentication popup carries its own #email/#password/#send2 — and
  // its own id="login-form", so duplicate ids defeat both a page-wide selector
  // and a #login-form-scoped one. Worse, that markup is a Knockout template:
  // the server sends one #send2 and a second appears once KO hydrates the
  // popup. So whether a click is ambiguous depends on whether hydration won
  // the race, which is why this passed for months and then failed on the
  // slower (enterprise) runners.
  //
  // .login-container is server-rendered, appears once, and never contains the
  // popup — so this cannot become ambiguous however the page hydrates.
  const form = page.locator('.login-container form#login-form');
  await expect(form, 'the customer login form should be on this page').toBeVisible({
    timeout: 40_000,
  });

  await form.locator('#email').fill(email);
  await form.locator('#password').fill(password);
  await form.locator('#send2').click();

  await page.waitForURL(/customer\/account/, { timeout: 40_000 });
  await expect(
    page.locator('.page-main'),
    `login failed for ${email} - check the seed ran (scripts/seed-test-data.php)`,
  ).toContainText(email, { timeout: 40_000 });
}

/** Log the plain (non-exempt) seeded customer in. */
export async function loginAsPlainCustomer(page: Page): Promise<void> {
  await loginAsCustomer(page, PLAIN_CUSTOMER_EMAIL);
}

/** Log the seeded customer holding the TX exemption certificate in. */
export async function loginAsExemptCustomer(page: Page): Promise<void> {
  await loginAsCustomer(page, EXEMPT_CUSTOMER_EMAIL);
}

/** Log the seeded Wholesale customer (no certificate of its own) in. */
export async function loginAsTrustedCustomer(page: Page): Promise<void> {
  await loginAsCustomer(page, TRUSTED_CUSTOMER_EMAIL);
}

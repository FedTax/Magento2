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
  await page.goto('/customer/account/login/');

  // Scoped to the login FORM, not the page. Luma also renders a hidden
  // "authentication popup" carrying an #email/#password/#send2 of its own, so
  // page-wide locators match two elements and Playwright refuses to guess.
  // It only stays hidden on a fresh session, which is why an unscoped selector
  // works right up until a spec visits another page first.
  const form = page.locator('#login-form');

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

import { type Page } from '@playwright/test';

/**
 * Admin login. The E2E install fixes the backend frontName to `admin` and
 * disables the 2FA modules (see scripts/install-magento.sh), so a plain
 * username/password login lands straight on the dashboard.
 */
export class AdminLoginPage {
  constructor(private readonly page: Page) {}

  async login(username = 'admin', password = '1234567a'): Promise<void> {
    await this.page.goto('/admin/');
    await this.page.locator('input[name="login[username]"]').fill(username);
    await this.page.locator('input[name="login[password]"]').fill(password);
    await this.page.locator('.action-login').click();
    // Dashboard (or wherever admin lands) — the global menu is always present.
    await this.page.locator('.admin__menu, .page-title').first().waitFor({ timeout: 40_000 });
  }
}

import { test } from '@playwright/test';
import { HomePage } from '../../pages/storefront/HomePage';

/**
 * Pipeline smoke test. Proves the whole E2E chain works end to end —
 * docker-compose.e2e.yml (nginx + php-fpm) serving the Magento install that
 * scripts/install-magento.sh provisioned, reached by a real Chromium over the
 * published port — without depending on any module feature. Real feature tests
 * (checkout tax, admin refund, address verification) land in a follow-up ticket.
 */
test('storefront homepage loads', async ({ page }) => {
  const home = new HomePage(page);

  await home.goto();
  await home.expectLoaded();
});

import { type Page } from '@playwright/test';

/**
 * Overlays the Magento admin puts in front of the page, and how to wait them
 * out.
 *
 * Every one of these covers the whole viewport while it is up, so a click
 * scheduled underneath one is intercepted rather than delivered. Playwright
 * retries an intercepted click until the action times out, which surfaces as
 * "element is visible, enabled and stable ... intercepts pointer events" after
 * 20+ seconds — a failure that reads like a missing button and is really a
 * race with a modal, a grid reload, or a page mask.
 *
 * These waits are what make an admin click depend on the page being ready
 * rather than on the machine being fast, which is the difference between a
 * suite that passes locally and one that also passes on a loaded CI runner.
 */
const OVERLAY_SELECTORS = [
  // vex modal backdrop (confirmations, third-party admin dialogs)
  '.vex-overlay',
  // Magento UI modals (confirm/alert/slide-out panels)
  '.modals-overlay',
  // full-page and grid loading masks
  '.loading-mask',
  '.admin__data-grid-loading-mask',
];

/**
 * Wait until nothing is covering the page.
 *
 * Each selector is waited out independently and failures are swallowed: an
 * overlay that never existed is the normal case, and one that outlives the
 * wait should fail the caller's own assertion with its own message, not here.
 */
export async function waitForOverlaysToClear(page: Page, timeout = 30_000): Promise<void> {
  for (const selector of OVERLAY_SELECTORS) {
    await page
      .locator(selector)
      .first()
      .waitFor({ state: 'hidden', timeout })
      .catch(() => {});
  }
}

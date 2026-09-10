import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { TaxConfigPage } from '../../pages/admin/TaxConfigPage';
import { ProductPage } from '../../pages/storefront/ProductPage';
import { CheckoutPage, type GuestAddress } from '../../pages/storefront/CheckoutPage';

/**
 * The User-Agent, observed on real wire traffic.
 *
 * The unit and integration suites prove the module *builds* the right string
 * and hands it to each transport. Neither watches a byte leave the process:
 * a header can be assembled correctly and still be dropped by the transport
 * that is supposed to send it. This spec closes that last gap by reading the
 * HTTP request headers the SOAP client actually emitted, which Advanced
 * logging records verbatim (Model\Api::logSoapTrace).
 *
 * SOAP only, deliberately: the v3 REST path logs bodies but not headers, so
 * there is nothing on disk to assert against. That path is covered by the
 * integration wiring test plus the live ping, and asserting a REST User-Agent
 * here would mean asserting on something this suite cannot actually see.
 *
 * The trigger is a checkout tax lookup, not the Test Connection button: the
 * connection test runs through SoapPing, which does not emit a wire trace.
 * Only Model\Api operations (lookupTaxes, authorizedWithCapture, Returned,
 * OrderDetails) call logSoapTrace, so a real tax operation is the only thing
 * that puts request headers on disk. The order is deliberately NOT placed —
 * the lookup on the totals step is enough, and it keeps this spec off the
 * capture path the journey suites own.
 *
 * State: Advanced logging is switched on for the duration and restored in a
 * finally, so a failure mid-spec cannot leave the install logging full
 * payloads for every later test.
 */

/** Seeded in-state address — same one the checkout journeys use. */
const TX_ADDRESS: GuestAddress = {
  email: 'ua-probe@example.com',
  firstname: 'Test',
  lastname: 'Buyer',
  street: '1401 Lavaca St',
  city: 'Austin',
  region: 'Texas',
  postcode: '78701',
  telephone: '5125550100',
};

/** Mirrors the resolution used by the taxcloudLog fixture. */
function resolveInstallDir(): string {
  const moduleRoot = path.resolve(__dirname, '..', '..', '..', '..');
  const fallback = `../magento-${process.env.MAGENTO_EDITION ?? 'community'}-${
    process.env.MAGENTO_VERSION ?? '2.4.8-p5'
  }`;
  const installDir = path.resolve(moduleRoot, process.env.MAGENTO_INSTALL_DIR ?? fallback);

  if (!fs.existsSync(path.join(installDir, 'app', 'bootstrap.php'))) {
    throw new Error(
      `admin-request-user-agent: no Magento install at ${installDir} — set MAGENTO_INSTALL_DIR ` +
        '(or MAGENTO_EDITION/MAGENTO_VERSION) to the install the e2e stack serves.'
    );
  }

  return installDir;
}

function logFile(): string {
  return path.join(resolveInstallDir(), 'var', 'log', 'taxcloud.log');
}

function logSize(file: string): number {
  return fs.existsSync(file) ? fs.statSync(file).size : 0;
}

/** Lines appended after a byte offset, tolerating rotation/truncation. */
function linesSince(file: string, offset: number): string[] {
  if (!fs.existsSync(file)) {
    return [];
  }
  const size = fs.statSync(file).size;
  const from = size < offset ? 0 : offset;
  if (size === from) {
    return [];
  }
  const fd = fs.openSync(file, 'r');
  try {
    const buffer = Buffer.alloc(size - from);
    fs.readSync(fd, buffer, 0, buffer.length, from);
    return buffer.toString('utf8').split('\n');
  } finally {
    fs.closeSync(fd);
  }
}

/**
 * The shape the module ships:
 *   TaxCloud-Magento2/1.4.0 Magento/2.4.8-p5 (Community) PHP/8.3.31
 * Matched structurally rather than against literal versions, so the spec does
 * not need editing at every release — while still failing if a component
 * degraded to "unknown" or the format drifted.
 */
const USER_AGENT = /User-Agent:\s*TaxCloud-Magento2\/(\S+) Magento\/(\S+) \(([^)]+)\) PHP\/(\S+)/i;

test.describe('API request User-Agent', () => {
  test('SOAP requests carry the extension, Magento and PHP versions', async ({ page }) => {
    // Admin round trip + a live TaxCloud lookup on the totals step.
    test.setTimeout(240_000);

    await new AdminLoginPage(page).login();
    const config = new TaxConfigPage(page);
    await config.open();

    const logging = page.locator('#tax_taxcloud_logging');
    const previous = await logging.inputValue();

    try {
      // 'Enable - Advanced' is the only mode that records HTTP request headers.
      await logging.selectOption('2');
      await config.save();

      const before = logSize(logFile());

      // Provoke a real lookupTaxes over SOAP: add to cart, then let the
      // checkout totals step run. That is the call whose HTTP request headers
      // Advanced logging writes out.
      const product = new ProductPage(page);
      const checkout = new CheckoutPage(page);

      await product.open('test-product');
      await product.addToCart();
      await checkout.open();
      await checkout.fillGuestShipping(TX_ADDRESS);
      await checkout.selectFlatRateAndContinue({ expectTax: true });

      const matched = linesSince(logFile(), before)
        .map((line) => line.match(USER_AGENT))
        .find((m): m is RegExpMatchArray => m !== null);

      expect(
        matched,
        'No User-Agent line was logged for the SOAP request. Advanced logging records the HTTP ' +
          'request headers verbatim, so either the header was not sent or the format changed.'
      ).toBeTruthy();

      const [, extension, magento, edition, php] = matched!;

      // A degraded component still produces a valid header, so the header
      // existing is not enough — it has to actually identify the install.
      for (const [name, value] of Object.entries({ extension, magento, edition, php })) {
        expect(value, `${name} must resolve on a real install`).not.toMatch(/^unknown$/i);
      }
      expect(extension, 'extension version').toMatch(/^\d+\.\d+\.\d+/);
      expect(php, 'PHP version').toMatch(/^\d+\.\d+\.\d+/);
    } finally {
      await config.open();
      await logging.selectOption(previous);
      await config.save();
    }
  });
});

/**
 * Server-side TaxCloud SOAP mocking for E2E — DEFERRED (not yet implemented).
 *
 * WHY THIS IS A STUB
 * ------------------
 * Unlike a typical front-end test, the browser never makes the TaxCloud SOAP
 * call — Magento's PHP does, server-side, during cart/checkout. So Playwright's
 * `page.route()` cannot intercept it; the mock has to live inside Magento.
 *
 * The integration suite already mocks SOAP in-process by swapping the
 * `\Magento\Framework\Webapi\Soap\ClientFactory` binding for a RecordingSoapClient
 * (see Test/Integration/IntegrationTestCase.php + Test/Integration/_files/
 * soap_responses/). That swap only works because PHPUnit shares the process with
 * Magento — it's unavailable across the HTTP boundary an E2E request crosses.
 *
 * The pipeline smoke test (specs/smoke/storefront-loads.spec.ts) only loads the
 * home page, which makes no SOAP call, so nothing here is needed yet. The
 * server-side mock lands with the first checkout E2E test in the "E2E test
 * coverage" ticket.
 *
 * INTENDED APPROACH (documented in docs/E2E_TESTS.md)
 * ---------------------------------------------------
 * A small dev-only Magento module, enabled only in the E2E install, that — when
 * a test-mode config flag is on — rebinds ClientFactory to a file-backed mock
 * reusing the existing Test/Integration/_files/soap_responses/ fixtures. Tests
 * assert on UI outcomes (e.g. the tax shown at checkout), so no per-test
 * response injection or call-recording endpoint is required for the first pass.
 *
 * The future fixture surface will look roughly like:
 *
 *   export const test = base.extend<{ soapMock: SoapMock }>({ ... });
 *   // await soapMock.useScenario('lookup_ok_empty');
 *
 * Until then this module intentionally exports nothing usable.
 */

export {};

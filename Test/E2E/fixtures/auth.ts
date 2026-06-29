/**
 * Authentication helpers for E2E — scaffold only.
 *
 * The pipeline smoke test runs as an anonymous guest, so no auth is needed yet.
 * Logged-in customer and admin flows arrive with the real test coverage; this
 * file marks where their helpers will live so specs import sessions from one
 * place instead of re-typing login steps.
 *
 * The seeded admin (scripts/seed-test-data.php) is admin / 1234567a. A first
 * adminLogin() will likely drive the admin login form at
 * `${baseURL}/admin` (or storageState capture for speed). Customer login will
 * need a seeded customer account, which the seed does not create yet — see the
 * "Reuse existing seed; defer extras" decision in docs/E2E_TESTS.md.
 *
 * Until those land this module intentionally exports nothing usable.
 */

export {};

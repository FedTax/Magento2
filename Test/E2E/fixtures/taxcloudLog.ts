import { test as base, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Gateway-error watcher.
 *
 * The module NEVER blocks checkout or admin flows on a TaxCloud failure — a
 * gateway operation that errors just logs `tclogger.ERROR` and the journey
 * completes normally. Correct in production, but it makes UI journeys
 * structurally blind to gateway failures (the 2026-08-07 configurable-refund
 * regression passed every suite this way). This fixture closes that gap:
 * it snapshots var/log/taxcloud.log before each test and fails the test when
 * any new ERROR line appeared while it ran.
 *
 * Opt in by importing { test, expect } from this file instead of
 * '@playwright/test'. Journeys should opt in; admin specs that deliberately
 * provoke failures (wrong-connection tests) should stay on the base test.
 */

/**
 * Resolve the Magento install the e2e stack serves. Mirrors the
 * docker-compose volume default exactly: MAGENTO_INSTALL_DIR when set,
 * otherwise ../magento-<edition>-<version> relative to the module root.
 * Deliberately no directory scanning — with several magento-* siblings on a
 * dev machine, guessing could watch the wrong install's log and report a
 * false green.
 */
function resolveInstallDir(): string {
  const moduleRoot = path.resolve(__dirname, '..', '..', '..');
  const fallback = `../magento-${process.env.MAGENTO_EDITION ?? 'community'}-${
    process.env.MAGENTO_VERSION ?? '2.4.8-p5'
  }`;
  const installDir = path.resolve(moduleRoot, process.env.MAGENTO_INSTALL_DIR ?? fallback);

  if (!fs.existsSync(path.join(installDir, 'app', 'bootstrap.php'))) {
    throw new Error(
      `taxcloudLog fixture: no Magento install at ${installDir} — set MAGENTO_INSTALL_DIR ` +
        '(or MAGENTO_EDITION/MAGENTO_VERSION) to the install the e2e stack serves, so gateway ' +
        'errors are watched in the right log.'
    );
  }

  return installDir;
}

function logFileFor(installDir: string): string {
  return path.join(installDir, 'var', 'log', 'taxcloud.log');
}

/** Log size, treating a not-yet-created file as empty. */
function logSize(file: string): number {
  return fs.existsSync(file) ? fs.statSync(file).size : 0;
}

/** New ERROR lines appended after the byte offset. */
function newErrorLines(file: string, offset: number): string[] {
  if (!fs.existsSync(file)) {
    return [];
  }
  const size = fs.statSync(file).size;
  // Log rotated/truncated mid-run: read it from the start.
  const from = size < offset ? 0 : offset;
  if (size === from) {
    return [];
  }
  const fd = fs.openSync(file, 'r');
  try {
    const buffer = Buffer.alloc(size - from);
    fs.readSync(fd, buffer, 0, buffer.length, from);
    return buffer
      .toString('utf8')
      .split('\n')
      .filter((line) => line.includes('.ERROR:'));
  } finally {
    fs.closeSync(fd);
  }
}

type TaxcloudLogFixtures = {
  gatewayErrorWatcher: void;
};

export const test = base.extend<TaxcloudLogFixtures>({
  gatewayErrorWatcher: [
    async ({}, use) => {
      const logFile = logFileFor(resolveInstallDir());
      const before = logSize(logFile);

      await use();

      const errors = newErrorLines(logFile, before);
      if (errors.length > 0) {
        // A plain throw rather than expect(): an assertion inside fixture
        // teardown makes Playwright emit "Internal error: step id not found"
        // noise in reporters.
        throw new Error(
          'TaxCloud logged gateway ERRORs during this journey — the flow completed in the UI ' +
            'because the module never blocks checkout on gateway failures, but the operation ' +
            `failed:\n${errors.join('\n')}`
        );
      }
    },
    { auto: true },
  ],
});

export { expect };

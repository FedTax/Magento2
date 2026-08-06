.PHONY: test test-unit test-unit-version lint lint-fix phpstan analyse help \
        integration-test integration-shell integration-clean \
        e2e-setup e2e-install e2e-test e2e-test-ui e2e-test-headed \
        e2e-trace e2e-clean

# Defaults — override on the command line, e.g.:
#   make integration-test MAGENTO_EDITION=enterprise MAGENTO_VERSION=2.4.8-p5 PHP_VERSION=8.2
MAGENTO_EDITION ?= community
MAGENTO_VERSION ?= 2.4.8-p5
# PHP_VERSION is deliberately empty by default: when unset, the install script
# falls back to .env's PHP_VERSION, then to 8.3. Setting it here would
# silently shadow .env.
PHP_VERSION ?=

# Magento installation this module lives inside. Unit tests run with that
# install's PHPUnit and real Magento classes — the module has no vendor/ of
# its own. Default assumes the conventional app/code/Taxcloud/Magento2 layout.
MAGENTO_ROOT ?= $(abspath ../../../..)

# Default target
help:
	@echo "Unit tests (run against the Magento install at $(MAGENTO_ROOT)):"
	@echo "  make test-unit         - Run unit tests via Magento's PHPUnit"
	@echo "  make test              - Alias for test-unit"
	@echo "                           Override install path with MAGENTO_ROOT=..."
	@echo ""
	@echo "  make test-unit-version - Run unit tests against a SPECIFIC Magento/PHP in Docker"
	@echo "                           (uses the integration stack's versioned install — that"
	@echo "                           version's bundled PHPUnit). Override with"
	@echo "                           MAGENTO_EDITION / MAGENTO_VERSION / PHP_VERSION, e.g.:"
	@echo "                             make test-unit-version MAGENTO_VERSION=2.4.7-p3 PHP_VERSION=8.2"
	@echo ""
	@echo "Static analysis:"
	@echo "  make phpstan           - Run PHPStan level 5 via the Magento install's binary"
	@echo "                           (alias: make analyse). Override MAGENTO_ROOT=..."
	@echo ""
	@echo "Integration tests (see docs/INTEGRATION_TESTS.md for setup):"
	@echo "  make integration-test  - Install Magento + run integration tests"
	@echo "                           Override with MAGENTO_EDITION / MAGENTO_VERSION / PHP_VERSION"
	@echo "  make integration-shell - Open a shell in the Magento container"
	@echo "  make integration-clean - Tear down docker compose volumes / containers"
	@echo ""
	@echo "E2E tests (Playwright; see docs/E2E_TESTS.md):"
	@echo "  make e2e-setup         - Install Magento + nginx and serve it over HTTP"
	@echo "                           (Magento install with the e2e overlay; ~10-15 min)"
	@echo "  make e2e-install       - Install Node deps + Chromium in Test/E2E"
	@echo "  make e2e-test          - Run the E2E suite headless against the running store"
	@echo "  make e2e-test-ui       - Open Playwright's interactive UI mode"
	@echo "  make e2e-test-headed   - Run headed (visible browser) for debugging"
	@echo "  make e2e-trace         - Open the trace viewer for the last failed run"
	@echo "  make e2e-clean         - Remove E2E artifacts (test-results, playwright-report)"
	@echo "                           First run from scratch:"
	@echo "                             make e2e-setup && make e2e-test"
	@echo ""
	@echo "Lint:"
	@echo "  make lint              - Run PHP CodeSniffer (Magento2 coding standard)"
	@echo "                           via the Magento install's binary. Override MAGENTO_ROOT=..."
	@echo "  make lint-fix          - Auto-fix linting issues where possible"
	@echo "  make help              - Show this help message"

# Run unit tests with the surrounding Magento install's PHPUnit
test-unit:
	@if [ ! -x "$(MAGENTO_ROOT)/vendor/bin/phpunit" ]; then \
		echo "ERROR: $(MAGENTO_ROOT)/vendor/bin/phpunit not found."; \
		echo "Unit tests run with the Magento install's PHPUnit. Either:"; \
		echo "  - check this module out at <magento-root>/app/code/Taxcloud/Magento2/, or"; \
		echo "  - pass MAGENTO_ROOT=/path/to/magento"; \
		exit 1; \
	fi
	@MAGENTO_ROOT=$(MAGENTO_ROOT) $(MAGENTO_ROOT)/vendor/bin/phpunit \
		-c phpunit.xml.dist Test/Unit/ --testdox

test: test-unit

# Run the UNIT suite against a SPECIFIC Magento/PHP combination in Docker, using
# that version's bundled PHPUnit (e.g. 2.4.7-p3 → PHPUnit 9/10, 2.4.9 → PHPUnit 12).
# Reuses the integration stack to provision the versioned Magento codebase; unit
# tests run from the full module mount at /srv/module (Test/Unit isn't linked into
# app/code), with MAGENTO_ROOT pointing at the install.
#   make test-unit-version MAGENTO_VERSION=2.4.7-p3 PHP_VERSION=8.2
test-unit-version:
	@if [ ! -f .env ]; then \
		echo "ERROR: .env not found."; \
		echo "  cp .env.example .env   # fill in Magento Marketplace keys"; \
		exit 1; \
	fi
	@UNIT_ONLY=1 ./scripts/install-magento.sh $(MAGENTO_EDITION) $(MAGENTO_VERSION) $(PHP_VERSION)
	@echo "==> Running unit suite against Magento $(MAGENTO_VERSION) (PHP $(PHP_VERSION))..."
	@docker compose exec -T -w /srv/module -e MAGENTO_ROOT=/var/www/html app \
		/var/www/html/vendor/bin/phpunit -c /srv/module/phpunit.xml.dist Test/Unit/ --testdox

# Run PHPStan static analysis (level 5) using the Magento install's phpstan binary.
# The module has no vendor/ of its own; phpstan-bootstrap.php resolves the real
# Magento classes via MAGENTO_ROOT (same model as test-unit). See phpstan.neon.
phpstan:
	@if [ ! -x "$(MAGENTO_ROOT)/vendor/bin/phpstan" ]; then \
		echo "ERROR: $(MAGENTO_ROOT)/vendor/bin/phpstan not found."; \
		echo "PHPStan runs with the Magento install's binary. Either:"; \
		echo "  - check this module out at <magento-root>/app/code/Taxcloud/Magento2/, or"; \
		echo "  - pass MAGENTO_ROOT=/path/to/magento"; \
		exit 1; \
	fi
	@MAGENTO_ROOT=$(MAGENTO_ROOT) $(MAGENTO_ROOT)/vendor/bin/phpstan analyse \
		-c phpstan.neon --no-progress --memory-limit=1G

analyse: phpstan

# Run PHP CodeSniffer against the Magento2 coding standard. No arguments: phpcs
# discovers phpcs.xml.dist, which carries the standard, the scanned paths and the
# deferrals — the same file CI runs, so the two cannot drift. See README
# "Coding standard". Uses the Magento install's phpcs (same model as test-unit /
# phpstan); magento/magento-coding-standard is one of its dev dependencies.
lint:
	@if [ ! -x "$(MAGENTO_ROOT)/vendor/bin/phpcs" ]; then \
		echo "ERROR: $(MAGENTO_ROOT)/vendor/bin/phpcs not found."; \
		echo "Linting runs with the Magento install's PHP CodeSniffer. Either:"; \
		echo "  - check this module out at <magento-root>/app/code/Taxcloud/Magento2/, or"; \
		echo "  - pass MAGENTO_ROOT=/path/to/magento"; \
		exit 1; \
	fi
	@echo "Running PHP CodeSniffer (Magento2 standard)..."
	@$(MAGENTO_ROOT)/vendor/bin/phpcs

# Auto-fix what the standard can fix mechanically (short array syntax, whitespace,
# import formatting). Anything left needs a human — run `make lint` to see it.
lint-fix:
	@if [ ! -x "$(MAGENTO_ROOT)/vendor/bin/phpcbf" ]; then \
		echo "ERROR: $(MAGENTO_ROOT)/vendor/bin/phpcbf not found."; \
		echo "  - check this module out at <magento-root>/app/code/Taxcloud/Magento2/, or"; \
		echo "  - pass MAGENTO_ROOT=/path/to/magento"; \
		exit 1; \
	fi
	@echo "Auto-fixing PHP CodeSniffer issues (Magento2 standard)..."
	@$(MAGENTO_ROOT)/vendor/bin/phpcbf

# ---------------------------------------------------------------------------
# Integration tests — see docs/INTEGRATION_TESTS.md
# ---------------------------------------------------------------------------

integration-test:
	@if [ ! -f .env ]; then \
		echo "ERROR: .env not found."; \
		echo ""; \
		echo "First-time setup:"; \
		echo "  cp .env.example .env"; \
		echo "  \$$EDITOR .env   # fill in TaxCloud sandbox + Magento Marketplace keys"; \
		echo ""; \
		exit 1; \
	fi
	@./scripts/install-magento.sh $(MAGENTO_EDITION) $(MAGENTO_VERSION) $(PHP_VERSION)
	@echo "==> Running integration test suite..."
	@docker compose exec -T -w /var/www/html app \
		vendor/bin/phpunit -c /srv/module/phpunit.integration.xml.dist --testdox

integration-shell:
	@docker compose exec -w /var/www/html app bash

integration-clean:
	@echo "Tearing down integration test stack (containers + volumes)..."
	@docker compose down -v --remove-orphans
	@echo
	@echo "Containers and volumes removed. The Magento install on disk is kept"
	@echo "so the next 'make integration-test' can re-use composer downloads."
	@echo "For a truly fresh install, also remove:"
	@echo "  $${MAGENTO_INSTALL_DIR:-../magento-$(MAGENTO_EDITION)-$(MAGENTO_VERSION)}"
	@echo "  ./.composer-cache"

# ---------------------------------------------------------------------------
# E2E tests (Playwright) — see docs/E2E_TESTS.md
#
# Same one-time setup as integration (cp .env.example .env; fill in keys), then:
#   make e2e-setup     # install Magento + nginx, serve over HTTP
#   make e2e-test      # run the browser suite (auto-installs Node deps first run)
#
# MAGENTO_BASE_URL must match the port nginx publishes (MAGENTO_HTTP_PORT in the
# install). Override here too if you changed it, e.g.:
#   make e2e-test MAGENTO_BASE_URL=http://localhost:9000
# ---------------------------------------------------------------------------

E2E_DIR ?= Test/E2E
MAGENTO_BASE_URL ?= http://localhost:8080

# Provision Magento with the e2e overlay (php-fpm + nginx) and serve it. Reuses
# scripts/install-magento.sh with E2E=1; the script ends by confirming the
# storefront is reachable.
e2e-setup:
	@if [ ! -f .env ]; then \
		echo "ERROR: .env not found."; \
		echo ""; \
		echo "First-time setup:"; \
		echo "  cp .env.example .env"; \
		echo "  \$$EDITOR .env   # fill in TaxCloud sandbox + Magento Marketplace keys"; \
		echo ""; \
		exit 1; \
	fi
	@E2E=1 ./scripts/install-magento.sh $(MAGENTO_EDITION) $(MAGENTO_VERSION) $(PHP_VERSION)

# Install the Node toolchain + the Chromium browser binary. `npm ci` is keyed to
# package-lock.json for reproducible installs.
e2e-install:
	@cd $(E2E_DIR) && npm ci && npx playwright install chromium

# Run the suite headless. Auto-installs Node deps on the first run, then refuses
# to run (with a pointer to e2e-setup) if the storefront isn't up.
e2e-test:
	@if [ ! -d $(E2E_DIR)/node_modules ]; then \
		echo "==> Installing E2E dependencies (first run)..."; \
		$(MAKE) e2e-install; \
	fi
	@curl -fsS -o /dev/null "$(MAGENTO_BASE_URL)/health_check.php" || { \
		echo "ERROR: Magento is not reachable at $(MAGENTO_BASE_URL)."; \
		echo "Bring the E2E stack up first:  make e2e-setup"; \
		echo "(If you changed the port, pass MAGENTO_BASE_URL=... to make as well.)"; \
		exit 1; \
	}
	@set -a; [ -f .env ] && . ./.env; set +a; cd $(E2E_DIR) && MAGENTO_BASE_URL=$(MAGENTO_BASE_URL) npx playwright test

# Interactive UI mode — watch/step through tests in a GUI.
e2e-test-ui:
	@set -a; [ -f .env ] && . ./.env; set +a; cd $(E2E_DIR) && MAGENTO_BASE_URL=$(MAGENTO_BASE_URL) npx playwright test --ui

# Headed run (visible browser) for debugging.
e2e-test-headed:
	@set -a; [ -f .env ] && . ./.env; set +a; cd $(E2E_DIR) && MAGENTO_BASE_URL=$(MAGENTO_BASE_URL) npx playwright test --headed

# Open the trace viewer for the most recent failed test (traces are
# retain-on-failure, so this only finds something after a failure).
e2e-trace:
	@cd $(E2E_DIR) && latest=$$(ls -dt test-results/*/trace.zip 2>/dev/null | head -1); \
	if [ -z "$$latest" ]; then \
		echo "No trace found under $(E2E_DIR)/test-results/."; \
		echo "Traces are captured on failure — run a failing test first."; \
	else \
		echo "==> Opening $$latest"; \
		npx playwright show-trace "$$latest"; \
	fi

# Remove E2E run artifacts (keeps node_modules and the browser cache).
e2e-clean:
	@echo "Removing E2E artifacts..."
	@rm -rf $(E2E_DIR)/test-results $(E2E_DIR)/playwright-report
	@echo "Removed $(E2E_DIR)/test-results and $(E2E_DIR)/playwright-report"

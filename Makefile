.PHONY: test test-unit lint lint-fix help \
        integration-test integration-shell integration-clean

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
	@echo "Integration tests (see docs/INTEGRATION_TESTS.md for setup):"
	@echo "  make integration-test  - Install Magento + run integration tests"
	@echo "                           Override with MAGENTO_EDITION / MAGENTO_VERSION / PHP_VERSION"
	@echo "  make integration-shell - Open a shell in the Magento container"
	@echo "  make integration-clean - Tear down docker compose volumes / containers"
	@echo ""
	@echo "Lint:"
	@echo "  make lint              - Run PHP CodeSniffer linting"
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

# Run PHP CodeSniffer linting
lint:
	@echo "Running PHP CodeSniffer..."
	@lint_output=$$(phpcs --standard=PSR2 --extensions=php Model/ Observer/ Logger/ Setup/ --ignore=Test/ 2>&1 || true); \
	if echo "$$lint_output" | grep -q "FOUND [1-9][0-9]* ERROR"; then \
		echo "❌ Linting failed - errors found:"; \
		echo "$$lint_output"; \
		exit 1; \
	else \
		echo "✅ Linting passed - only warnings found (non-fatal):"; \
		echo "$$lint_output"; \
	fi

# Auto-fix PHP CodeSniffer issues where possible
lint-fix:
	@echo "Auto-fixing PHP CodeSniffer issues..."
	@phpcbf --standard=PSR2 --extensions=php Model/ Observer/ Logger/ Setup/ --ignore=Test/

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

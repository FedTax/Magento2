.PHONY: test test-local test-unit lint lint-fix help \
        integration-test integration-shell integration-clean

# Defaults — override on the command line, e.g.:
#   make integration-test MAGENTO_EDITION=enterprise MAGENTO_VERSION=2.4.8-p5
MAGENTO_EDITION ?= community
MAGENTO_VERSION ?= 2.4.8-p5

# Default target
help:
	@echo "Unit tests:"
	@echo "  make test              - Run unit tests using Docker"
	@echo "  make test-local        - Run unit tests locally (requires PHP)"
	@echo "  make test-unit         - Run unit tests only"
	@echo ""
	@echo "Integration tests (see docs/INTEGRATION_TESTS.md for setup):"
	@echo "  make integration-test  - Install Magento + run integration tests"
	@echo "                           Override with MAGENTO_EDITION / MAGENTO_VERSION"
	@echo "  make integration-shell - Open a shell in the Magento container"
	@echo "  make integration-clean - Tear down docker compose volumes / containers"
	@echo ""
	@echo "Lint:"
	@echo "  make lint              - Run PHP CodeSniffer linting"
	@echo "  make lint-fix          - Auto-fix linting issues where possible"
	@echo "  make help              - Show this help message"

# Run all tests using Docker
test:
	@echo "Running all tests with Docker..."
	@./run-test.sh

# Run tests locally (requires PHP)
test-local:
	@echo "Running unit tests locally..."
	@vendor/bin/phpunit Test/Unit/ --testdox

# Run unit tests only
test-unit:
	@echo "Running unit tests..."
	@vendor/bin/phpunit Test/Unit/ --testdox

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
	@./scripts/install-magento.sh $(MAGENTO_EDITION) $(MAGENTO_VERSION)
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

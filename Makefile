include .dev-kit/mk/common.mk

.PHONY: _test _lint test-local test-unit test-compatibility lint-fix

# --------------------------------------------------------------------------
# Standard interface implementations
# --------------------------------------------------------------------------

##@ Testing

_test: ## Run all tests using Docker
	@echo "Running all tests with Docker..."
	@./run-test.sh

test-local: ## Run all tests locally (requires PHP)
	@echo "Running all tests locally..."
	@vendor/bin/phpunit Test/Unit/ --testdox
	@for test_file in Test/Integration/*.php; do \
		echo "Running $$test_file..."; \
		php "$$test_file"; \
	done

test-unit: ## Run unit tests only
	@echo "Running unit tests..."
	@vendor/bin/phpunit Test/Unit/ --testdox

test-compatibility: ## Run Adobe Commerce 2.4.8-p1 compatibility tests
	@echo "Running Adobe Commerce 2.4.8-p1 compatibility tests..."
	@php Test/Integration/AdobeCommerce248p1CompatibilityTest.php

##@ Code Quality

_lint: ## Run PHP CodeSniffer linting
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

lint-fix: ## Auto-fix PHP CodeSniffer issues where possible
	@echo "Auto-fixing PHP CodeSniffer issues..."
	@phpcbf --standard=PSR2 --extensions=php Model/ Observer/ Logger/ Setup/ --ignore=Test/

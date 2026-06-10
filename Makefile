.PHONY: test test-local test-unit lint lint-fix help

# Default target
help:
	@echo "Available commands:"
	@echo "  make test              - Run unit tests using Docker"
	@echo "  make test-local        - Run unit tests locally (requires PHP)"
	@echo "  make test-unit         - Run unit tests only"
	@echo "  make lint              - Run PHP CodeSniffer linting"
	@echo "  make lint-fix          - Auto-fix linting issues where possible"
	@echo "  make help              - Show this help message"
	@echo ""
	@echo "Integration tests are documented in docs/INTEGRATION_TESTS.md."

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

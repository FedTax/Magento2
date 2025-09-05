.PHONY: test test-local test-compatibility help

# Default target
help:
	@echo "Available commands:"
	@echo "  make test              - Run all tests using Docker"
	@echo "  make test-local        - Run all tests locally (requires PHP)"
	@echo "  make test-compatibility - Run Adobe Commerce 2.4.8-p1 compatibility tests"
	@echo "  make help              - Show this help message"

# Run all tests using Docker
test:
	@echo "Running all tests with Docker..."
	@./run-test.sh

# Run tests locally (requires PHP)
test-local:
	@echo "Running all tests locally..."
	@echo "Running Integration Tests..."
	@for test_file in Test/Integration/*.php; do \
		echo "Running $$test_file..."; \
		php "$$test_file"; \
	done

# Run Adobe Commerce 2.4.8-p1 compatibility tests
test-compatibility:
	@echo "Running Adobe Commerce 2.4.8-p1 compatibility tests..."
	@php Test/Integration/AdobeCommerce248p1CompatibilityTest.php

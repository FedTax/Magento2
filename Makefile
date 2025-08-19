.PHONY: test test-local help

# Default target
help:
	@echo "Available commands:"
	@echo "  make test        - Run all tests using Docker"
	@echo "  make test-local  - Run all tests locally (requires PHP)"
	@echo "  make help        - Show this help message"

# Run all tests using Docker
test:
	@echo "Running all tests with Docker..."
	@./run-test.sh

# Run tests locally (requires PHP)
test-local:
	@echo "Running all tests locally..."
	@for test_file in Test/Integration/*.php; do \
		echo "Running $$test_file..."; \
		php "$$test_file"; \
	done

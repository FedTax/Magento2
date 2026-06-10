#!/bin/bash
#
# Run the module's unit test suite inside a disposable PHP container.
# For integration tests, see docs/INTEGRATION_TESTS.md and `make integration-test`.

set -e

if ! command -v docker &> /dev/null; then
    echo "Docker not found. Please install Docker first."
    exit 1
fi

if [ ! -x "vendor/bin/phpunit" ]; then
    echo "vendor/bin/phpunit not found. Run 'composer install' on the host first."
    exit 1
fi

echo "Running unit tests in php:8.1-cli..."
docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli vendor/bin/phpunit Test/Unit/ --testdox

echo "Tests completed!"

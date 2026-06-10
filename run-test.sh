#!/bin/bash

set -e

echo "Running unit tests..."

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "Docker not found. Please install Docker first."
    exit 1
fi

# Run unit tests in a PHP container
docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli bash -c "
    composer install --no-interaction --prefer-dist --optimize-autoloader
    vendor/bin/phpunit Test/Unit/ --testdox
"

echo "Tests completed!"

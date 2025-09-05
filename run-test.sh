#!/bin/bash

echo "Running all tests..."

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "Docker not found. Please install Docker first."
    exit 1
fi

# Run all tests in a PHP container
docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli bash -c "
    echo 'Running Integration Tests...'
    for test_file in Test/Integration/*.php; do
        echo \"Running \$test_file...\"
        php \"\$test_file\"
    done
"

echo "Tests completed!"

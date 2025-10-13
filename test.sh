#!/bin/bash
# Simple test runner

set -e

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

# Run tests
composer test:quick

#!/bin/bash
# Helper script to run tests

set -e

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

# Run tests
case "${1:-quick}" in
    "quick")
        composer test:quick
        ;;
    "coverage")
        composer test:coverage
        ;;
    "ci")
        composer test:ci
        ;;
    *)
        composer test
        ;;
esac

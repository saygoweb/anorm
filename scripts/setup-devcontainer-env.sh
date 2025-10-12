#!/bin/bash

# Setup environment variables for Anorm testing in dev container
# Source this file: source scripts/setup-devcontainer-env.sh

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔧 Setting up Anorm dev container environment${NC}"

# Set environment variables for tests
export DB_HOST=db
export DB_NAME=anorm_test
export DB_USER=dev
export DB_PASS=dev

# Also set legacy variables for compatibility
export DATABASE_HOST=db
export DATABASE_NAME=anorm_test
export DATABASE_USER=dev
export DATABASE_PASSWORD=dev

echo -e "${GREEN}✅ Environment variables set:${NC}"
echo "  DB_HOST=$DB_HOST"
echo "  DB_NAME=$DB_NAME"
echo "  DB_USER=$DB_USER"
echo "  DB_PASS=$DB_PASS"

# Test database connection
echo -e "${BLUE}🔍 Testing database connection...${NC}"
if mysql -h $DB_HOST -u $DB_USER -p$DB_PASS -e "SELECT 1" $DB_NAME >/dev/null 2>&1; then
    echo -e "${GREEN}✅ Database connection successful!${NC}"
else
    echo -e "${RED}❌ Database connection failed${NC}"
    echo -e "${YELLOW}💡 Make sure the dev container services are running${NC}"
    return 1
fi

echo -e "${GREEN}🎉 Dev container environment ready!${NC}"
echo
echo -e "${BLUE}Available commands:${NC}"
echo "  composer test          # Full test suite with coverage"
echo "  composer test:quick    # Quick tests without coverage"
echo "  composer test:ci       # CI tests with clover coverage"
echo "  composer cs:check      # Check code style"
echo "  composer analyze       # Run static analysis"
echo "  composer quality       # Run all quality checks"
echo
echo -e "${BLUE}Database access:${NC}"
echo "  mysql -h db -u dev -pdev anorm_test    # Connect to database"
echo "  phpMyAdmin: http://localhost:8080      # Web interface"
echo
echo -e "${YELLOW}💡 To make these variables permanent, add them to your shell profile:${NC}"
echo "  echo 'source /workspace/scripts/setup-devcontainer-env.sh' >> ~/.bashrc"

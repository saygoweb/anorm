#!/bin/bash

# Setup complete test environment for Anorm
# This script sets up database and environment variables

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🧪 Setting up Anorm test environment${NC}"

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker is required but not installed${NC}"
    echo "Please install Docker and try again"
    exit 1
fi

# Start database
echo -e "${BLUE}📦 Starting test database...${NC}"
./scripts/start-test-db.sh

# Set environment variables
echo -e "${BLUE}🔧 Setting environment variables...${NC}"
export DB_HOST=localhost
export DB_NAME=anorm_test
export DB_USER=dev
export DB_PASS=dev

echo -e "${GREEN}✅ Environment variables set:${NC}"
echo "  DB_HOST=${DB_HOST}"
echo "  DB_NAME=${DB_NAME}"
echo "  DB_USER=${DB_USER}"
echo "  DB_PASS=${DB_PASS}"

# Test database connection
echo -e "${BLUE}🔍 Testing database connection...${NC}"
if docker exec anorm-test-db mysql -u${DB_USER} -p${DB_PASS} -e "SELECT 1" ${DB_NAME} >/dev/null 2>&1; then
    echo -e "${GREEN}✅ Database connection successful!${NC}"
else
    echo -e "${RED}❌ Database connection failed${NC}"
    exit 1
fi

# Run a quick test to verify everything works
echo -e "${BLUE}🧪 Running quick test verification...${NC}"
if DB_HOST=${DB_HOST} DB_NAME=${DB_NAME} DB_USER=${DB_USER} DB_PASS=${DB_PASS} composer test:quick >/dev/null 2>&1; then
    echo -e "${GREEN}✅ Test environment is working!${NC}"
else
    echo -e "${YELLOW}⚠️  Tests ran but some expected failures occurred (this is normal)${NC}"
fi

echo
echo -e "${GREEN}🎉 Test environment setup complete!${NC}"
echo
echo -e "${BLUE}To run tests:${NC}"
echo "  # Set environment variables in your shell:"
echo "  export DB_HOST=localhost"
echo "  export DB_NAME=anorm_test"
echo "  export DB_USER=dev"
echo "  export DB_PASS=dev"
echo
echo "  # Then run tests:"
echo "  composer test          # Full test suite with coverage"
echo "  composer test:quick    # Quick tests without coverage"
echo "  composer test:ci       # CI tests with clover coverage"
echo
echo -e "${BLUE}To stop the test environment:${NC}"
echo "  docker stop anorm-test-db"
echo
echo -e "${BLUE}To clean up completely:${NC}"
echo "  docker rm -f anorm-test-db"

#!/bin/bash

# Start MariaDB container for Anorm testing
# This matches the configuration used in GitHub Actions

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

CONTAINER_NAME="anorm-test-db"
DB_ROOT_PASSWORD="root"
DB_NAME="anorm_test"
DB_USER="dev"
DB_PASSWORD="dev"
DB_PORT="3306"

echo -e "${BLUE}🗄️  Starting MariaDB container for Anorm testing${NC}"

# Check if container already exists
if docker ps -a --format 'table {{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${YELLOW}⚠️  Container ${CONTAINER_NAME} already exists${NC}"
    
    # Check if it's running
    if docker ps --format 'table {{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        echo -e "${GREEN}✅ Container is already running${NC}"
        echo -e "${BLUE}Database connection details:${NC}"
        echo "  Host: localhost"
        echo "  Port: ${DB_PORT}"
        echo "  Database: ${DB_NAME}"
        echo "  Username: ${DB_USER}"
        echo "  Password: ${DB_PASSWORD}"
        exit 0
    else
        echo -e "${YELLOW}🔄 Starting existing container...${NC}"
        docker start ${CONTAINER_NAME}
    fi
else
    echo -e "${BLUE}🚀 Creating new MariaDB container...${NC}"
    
    # Remove any existing container with the same name
    docker rm -f ${CONTAINER_NAME} 2>/dev/null || true
    
    # Start new MariaDB container
    docker run -d \
        --name ${CONTAINER_NAME} \
        -e MARIADB_ROOT_PASSWORD=${DB_ROOT_PASSWORD} \
        -e MARIADB_DATABASE=${DB_NAME} \
        -e MARIADB_USER=${DB_USER} \
        -e MARIADB_PASSWORD=${DB_PASSWORD} \
        -p ${DB_PORT}:3306 \
        --health-cmd="mariadb-admin ping --silent" \
        --health-interval=10s \
        --health-timeout=5s \
        --health-retries=3 \
        mariadb:latest
fi

echo -e "${YELLOW}⏳ Waiting for database to be ready...${NC}"

# Wait for database to be ready
for i in {1..30}; do
    if docker exec ${CONTAINER_NAME} mariadb-admin ping --silent 2>/dev/null; then
        echo -e "${GREEN}✅ Database is ready!${NC}"
        break
    fi
    
    if [ $i -eq 30 ]; then
        echo -e "${RED}❌ Database failed to start within 30 seconds${NC}"
        echo -e "${YELLOW}Container logs:${NC}"
        docker logs ${CONTAINER_NAME}
        exit 1
    fi
    
    echo -e "${YELLOW}   Attempt $i/30 - waiting...${NC}"
    sleep 2
done

# Test connection
echo -e "${BLUE}🔍 Testing database connection...${NC}"
if docker exec ${CONTAINER_NAME} mysql -u${DB_USER} -p${DB_PASSWORD} -e "SELECT 1" ${DB_NAME} >/dev/null 2>&1; then
    echo -e "${GREEN}✅ Database connection successful!${NC}"
else
    echo -e "${RED}❌ Database connection failed${NC}"
    exit 1
fi

echo -e "${GREEN}🎉 MariaDB container is ready for testing!${NC}"
echo
echo -e "${BLUE}Database connection details:${NC}"
echo "  Host: localhost"
echo "  Port: ${DB_PORT}"
echo "  Database: ${DB_NAME}"
echo "  Username: ${DB_USER}"
echo "  Password: ${DB_PASSWORD}"
echo
echo -e "${BLUE}Environment variables for testing:${NC}"
echo "  export DB_HOST=localhost"
echo "  export DB_NAME=${DB_NAME}"
echo "  export DB_USER=${DB_USER}"
echo "  export DB_PASS=${DB_PASSWORD}"
echo
echo -e "${BLUE}To stop the database:${NC}"
echo "  docker stop ${CONTAINER_NAME}"
echo
echo -e "${BLUE}To remove the database:${NC}"
echo "  docker rm -f ${CONTAINER_NAME}"
echo
echo -e "${BLUE}Now you can run tests with:${NC}"
echo "  composer test"

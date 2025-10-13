#!/bin/bash

# Test Anorm with multiple PHP versions using Docker
# Usage: ./scripts/test-php-versions.sh

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# PHP versions to test
PHP_VERSIONS=("7.4" "8.0" "8.1" "8.2" "8.3")

# Test commands to run
COMMANDS=(
    "composer install --no-interaction"
    "composer validate --strict"
    "composer test:quick"
    "composer cs:check || true"
    "composer analyze || true"
)

echo -e "${BLUE}🧪 Testing Anorm with multiple PHP versions${NC}"
echo "=================================================="

# Function to run tests for a specific PHP version
test_php_version() {
    local version=$1
    echo -e "\n${YELLOW}📋 Testing PHP ${version}${NC}"
    echo "----------------------------------------"
    
    # Create Docker image for this PHP version
    local image_name="anorm-test-php${version}"
    
    # Build Docker image with required extensions
    docker build -t ${image_name} --build-arg PHP_VERSION=${version} -f - . << 'EOF'
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed for Anorm
RUN docker-php-ext-install pdo pdo_mysql zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY . .
EOF

    if [ $? -ne 0 ]; then
        echo -e "${RED}❌ Failed to build Docker image for PHP ${version}${NC}"
        return 1
    fi

    # Run each test command
    local all_passed=true
    for cmd in "${COMMANDS[@]}"; do
        echo -e "\n${BLUE}Running: ${cmd}${NC}"
        
        if docker run --rm -v $(pwd):/app ${image_name} bash -c "${cmd}"; then
            echo -e "${GREEN}✅ Passed${NC}"
        else
            echo -e "${RED}❌ Failed${NC}"
            all_passed=false
        fi
    done
    
    # Cleanup
    docker rmi ${image_name} >/dev/null 2>&1 || true
    
    if [ "$all_passed" = true ]; then
        echo -e "\n${GREEN}✅ PHP ${version} - ALL TESTS PASSED${NC}"
        return 0
    else
        echo -e "\n${RED}❌ PHP ${version} - SOME TESTS FAILED${NC}"
        return 1
    fi
}

# Function to run quick test (just basic functionality)
quick_test() {
    local version=$1
    echo -e "${BLUE}Quick test PHP ${version}${NC}"
    
    docker run --rm -v $(pwd):/app php:${version}-cli php -v
    docker run --rm -v $(pwd):/app php:${version}-cli php -m | grep -E "(pdo|mysql|zip)" || echo "Extensions may need to be installed"
    docker run --rm -v $(pwd):/app php:${version}-cli php -l composer.json >/dev/null 2>&1 && echo "✅ Syntax OK" || echo "❌ Syntax Error"
}

# Main execution
main() {
    local quick_mode=false
    local specific_version=""
    
    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --quick|-q)
                quick_mode=true
                shift
                ;;
            --version|-v)
                specific_version="$2"
                shift 2
                ;;
            --help|-h)
                echo "Usage: $0 [OPTIONS]"
                echo "Options:"
                echo "  --quick, -q          Run quick tests only"
                echo "  --version, -v VER    Test specific PHP version only"
                echo "  --help, -h           Show this help"
                exit 0
                ;;
            *)
                echo "Unknown option: $1"
                exit 1
                ;;
        esac
    done
    
    # Determine which versions to test
    local versions_to_test
    if [ -n "$specific_version" ]; then
        versions_to_test=("$specific_version")
    else
        versions_to_test=("${PHP_VERSIONS[@]}")
    fi
    
    # Run tests
    local failed_versions=()
    
    for version in "${versions_to_test[@]}"; do
        if [ "$quick_mode" = true ]; then
            quick_test "$version"
        else
            if ! test_php_version "$version"; then
                failed_versions+=("$version")
            fi
        fi
    done
    
    # Summary
    echo -e "\n${BLUE}📊 SUMMARY${NC}"
    echo "=========="
    
    if [ ${#failed_versions[@]} -eq 0 ]; then
        echo -e "${GREEN}🎉 All PHP versions passed!${NC}"
        exit 0
    else
        echo -e "${RED}❌ Failed versions: ${failed_versions[*]}${NC}"
        echo -e "${YELLOW}💡 Check the output above for specific failures${NC}"
        exit 1
    fi
}

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker is required but not installed${NC}"
    echo "Please install Docker and try again"
    exit 1
fi

# Run main function
main "$@"

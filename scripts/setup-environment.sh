#!/bin/bash

# Comprehensive Environment Setup Script for Anorm
# This script sets up everything needed to run Anorm tests successfully
# Supports both local PHP and Docker-based development

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DEFAULT_PHP_VERSION="8.1"
CONTAINER_PREFIX="anorm"

# Function to print colored output
print_status() {
    echo -e "${BLUE}🔧 $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${NC}"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to check Docker availability
check_docker() {
    print_status "Checking Docker availability..."
    
    if ! command_exists docker; then
        print_error "Docker is required but not installed"
        print_info "Please install Docker and try again"
        print_info "Visit: https://docs.docker.com/get-docker/"
        exit 1
    fi
    
    if ! docker info >/dev/null 2>&1; then
        print_error "Docker is installed but not running"
        print_info "Please start Docker and try again"
        exit 1
    fi
    
    print_success "Docker is available and running"
}

# Function to check PHP availability
check_php() {
    print_status "Checking PHP availability..."
    
    if command_exists php; then
        PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
        print_success "PHP $PHP_VERSION found locally"
        
        # Check for required extensions
        REQUIRED_EXTENSIONS=("pdo" "pdo_mysql" "zip")
        MISSING_EXTENSIONS=()
        
        for ext in "${REQUIRED_EXTENSIONS[@]}"; do
            if ! php -m | grep -q "^$ext$"; then
                MISSING_EXTENSIONS+=("$ext")
            fi
        done
        
        if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
            print_warning "Missing PHP extensions: ${MISSING_EXTENSIONS[*]}"
            print_info "Will use Docker for PHP environment"
            USE_DOCKER_PHP=true
        else
            print_success "All required PHP extensions are available"
            USE_DOCKER_PHP=false
        fi
        
        # Check for Composer
        if command_exists composer; then
            print_success "Composer found locally"
            USE_DOCKER_COMPOSER=false
        else
            print_warning "Composer not found locally"
            print_info "Will use Docker for Composer"
            USE_DOCKER_COMPOSER=true
        fi
    else
        print_warning "PHP not found locally"
        print_info "Will use Docker for PHP environment"
        USE_DOCKER_PHP=true
        USE_DOCKER_COMPOSER=true
    fi
}

# Function to setup database
setup_database() {
    print_status "Setting up test database..."
    
    # Use the existing database setup script
    if [ -f "$SCRIPT_DIR/start-test-db.sh" ]; then
        bash "$SCRIPT_DIR/start-test-db.sh"
    else
        print_error "Database setup script not found"
        exit 1
    fi
    
    print_success "Database setup completed"
}

# Function to build Docker images if needed
build_docker_images() {
    if [ "$USE_DOCKER_PHP" = true ] || [ "$USE_DOCKER_COMPOSER" = true ]; then
        print_status "Building Docker images..."
        
        cd "$PROJECT_ROOT"
        
        # Build the test image for the default PHP version
        docker build -f Dockerfile.test \
            --build-arg PHP_VERSION="$DEFAULT_PHP_VERSION" \
            -t "${CONTAINER_PREFIX}-php:${DEFAULT_PHP_VERSION}" \
            .
        
        print_success "Docker images built successfully"
    fi
}

# Function to install dependencies
install_dependencies() {
    print_status "Installing PHP dependencies..."
    
    cd "$PROJECT_ROOT"
    
    if [ "$USE_DOCKER_COMPOSER" = true ]; then
        print_info "Using Docker for Composer..."
        docker run --rm \
            -v "$PROJECT_ROOT:/app" \
            -w /app \
            "${CONTAINER_PREFIX}-php:${DEFAULT_PHP_VERSION}" \
            composer install --no-interaction --prefer-dist
    else
        print_info "Using local Composer..."
        composer install --no-interaction --prefer-dist
    fi
    
    print_success "Dependencies installed successfully"
}

# Function to set environment variables
setup_environment_variables() {
    print_status "Setting up environment variables..."
    
    # Create .env file for local development
    cat > "$PROJECT_ROOT/.env" << EOF
# Database configuration for testing
DB_HOST=localhost
DB_NAME=anorm_test
DB_USER=dev
DB_PASS=dev
DB_PORT=3306

# PHP configuration
PHP_VERSION=$DEFAULT_PHP_VERSION
USE_DOCKER_PHP=$USE_DOCKER_PHP
USE_DOCKER_COMPOSER=$USE_DOCKER_COMPOSER
EOF
    
    # Export variables for current session
    export DB_HOST=localhost
    export DB_NAME=anorm_test
    export DB_USER=dev
    export DB_PASS=dev
    export DB_PORT=3306
    
    print_success "Environment variables configured"
    print_info "Variables saved to .env file and exported to current session"
}

# Function to verify setup
verify_setup() {
    print_status "Verifying setup..."
    
    cd "$PROJECT_ROOT"
    
    # Test database connection
    print_info "Testing database connection..."
    if docker exec anorm-test-db mysql -udev -pdev -e "SELECT 1" anorm_test >/dev/null 2>&1; then
        print_success "Database connection successful"
    else
        print_error "Database connection failed"
        return 1
    fi
    
    # Run a quick test
    print_info "Running quick test verification..."
    
    if [ "$USE_DOCKER_PHP" = true ]; then
        # Use Docker to run tests
        TEST_RESULT=$(docker run --rm \
            -v "$PROJECT_ROOT:/app" \
            -w /app \
            --network host \
            -e DB_HOST=localhost \
            -e DB_NAME=anorm_test \
            -e DB_USER=dev \
            -e DB_PASS=dev \
            "${CONTAINER_PREFIX}-php:${DEFAULT_PHP_VERSION}" \
            composer test:quick 2>&1 || echo "FAILED")
    else
        # Use local PHP
        TEST_RESULT=$(DB_HOST=localhost DB_NAME=anorm_test DB_USER=dev DB_PASS=dev composer test:quick 2>&1 || echo "FAILED")
    fi
    
    if echo "$TEST_RESULT" | grep -q "FAILED"; then
        print_warning "Some tests failed, but this might be expected for initial setup"
        print_info "You can run 'composer test' to see detailed results"
    else
        print_success "Test verification completed successfully"
    fi
    
    print_success "Setup verification completed"
}

# Function to display usage information
show_usage() {
    print_status "Environment setup completed successfully!"
    echo
    print_info "Available commands:"
    echo
    
    if [ "$USE_DOCKER_PHP" = true ]; then
        echo -e "${CYAN}  # Run tests using Docker:${NC}"
        echo "  docker run --rm -v \$(pwd):/app -w /app --network host \\"
        echo "    -e DB_HOST=localhost -e DB_NAME=anorm_test -e DB_USER=dev -e DB_PASS=dev \\"
        echo "    ${CONTAINER_PREFIX}-php:${DEFAULT_PHP_VERSION} composer test"
        echo
        echo -e "${CYAN}  # Quick tests:${NC}"
        echo "  docker run --rm -v \$(pwd):/app -w /app --network host \\"
        echo "    -e DB_HOST=localhost -e DB_NAME=anorm_test -e DB_USER=dev -e DB_PASS=dev \\"
        echo "    ${CONTAINER_PREFIX}-php:${DEFAULT_PHP_VERSION} composer test:quick"
        echo
        echo -e "${CYAN}  # Interactive shell:${NC}"
        echo "  docker run -it --rm -v \$(pwd):/app -w /app --network host \\"
        echo "    -e DB_HOST=localhost -e DB_NAME=anorm_test -e DB_USER=dev -e DB_PASS=dev \\"
        echo "    ${CONTAINER_PREFIX}-php:${DEFAULT_PHP_VERSION} bash"
    else
        echo -e "${CYAN}  # Run tests locally:${NC}"
        echo "  composer test          # Full test suite with coverage"
        echo "  composer test:quick    # Quick tests without coverage"
        echo "  composer test:ci       # CI tests with clover coverage"
    fi
    
    echo
    echo -e "${CYAN}  # Code quality:${NC}"
    echo "  composer cs:check      # Check coding standards"
    echo "  composer cs:fix        # Fix coding standards"
    echo "  composer analyze       # Run static analysis"
    echo "  composer quality       # Run all quality checks"
    echo
    echo -e "${CYAN}  # Database management:${NC}"
    echo "  docker stop anorm-test-db     # Stop test database"
    echo "  docker start anorm-test-db    # Start test database"
    echo "  docker rm -f anorm-test-db    # Remove test database"
    echo
    echo -e "${CYAN}  # Environment:${NC}"
    echo "  source .env            # Load environment variables"
    echo "  cat .env               # View environment configuration"
}

# Main execution
main() {
    echo -e "${PURPLE}🚀 Anorm Development Environment Setup${NC}"
    echo -e "${PURPLE}======================================${NC}"
    echo
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    # Run setup steps
    check_docker
    check_php
    setup_database
    build_docker_images
    install_dependencies
    setup_environment_variables
    verify_setup
    
    echo
    show_usage
    
    echo
    print_success "🎉 Environment setup completed successfully!"
    print_info "You can now run tests and start developing!"
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: $0 [options]"
        echo
        echo "Options:"
        echo "  --help, -h     Show this help message"
        echo "  --php-version  Specify PHP version for Docker (default: $DEFAULT_PHP_VERSION)"
        echo
        echo "This script sets up the complete development environment for Anorm,"
        echo "including database, PHP dependencies, and test environment."
        exit 0
        ;;
    --php-version)
        if [ -n "${2:-}" ]; then
            DEFAULT_PHP_VERSION="$2"
            shift 2
        else
            print_error "PHP version not specified"
            exit 1
        fi
        ;;
esac

# Run main function
main "$@"

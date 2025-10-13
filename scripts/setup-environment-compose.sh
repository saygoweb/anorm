#!/bin/bash

# Docker Compose Environment Setup Script for Anorm
# This script uses docker-compose to set up the complete test environment
# Works around Docker overlay filesystem issues

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

# Function to check Docker and Docker Compose availability
check_docker() {
    print_status "Checking Docker and Docker Compose availability..."
    
    if ! command_exists docker; then
        print_error "Docker is required but not installed"
        print_info "Please install Docker and try again"
        exit 1
    fi
    
    if ! command_exists docker-compose; then
        print_error "Docker Compose is required but not installed"
        print_info "Please install Docker Compose and try again"
        exit 1
    fi
    
    if ! docker info >/dev/null 2>&1; then
        print_error "Docker is installed but not running"
        print_info "Please start Docker and try again"
        exit 1
    fi
    
    print_success "Docker and Docker Compose are available"
}

# Function to clean up any existing containers
cleanup_existing() {
    print_status "Cleaning up any existing containers..."
    
    cd "$PROJECT_ROOT"
    
    # Stop and remove any existing containers
    docker-compose -f docker-compose.test.yml down --remove-orphans >/dev/null 2>&1 || true
    
    # Also clean up the standalone database container if it exists
    docker stop anorm-test-db >/dev/null 2>&1 || true
    docker rm -f anorm-test-db >/dev/null 2>&1 || true
    
    print_success "Cleanup completed"
}

# Function to start services using docker-compose
start_services() {
    print_status "Starting services with Docker Compose..."
    
    cd "$PROJECT_ROOT"
    
    # Start only the database first
    print_info "Starting database service..."
    docker-compose -f docker-compose.test.yml up -d db
    
    # Wait for database to be ready
    print_info "Waiting for database to be ready..."
    for i in {1..30}; do
        if docker-compose -f docker-compose.test.yml exec -T db mariadb-admin ping --silent 2>/dev/null; then
            print_success "Database is ready!"
            break
        fi
        
        if [ $i -eq 30 ]; then
            print_error "Database failed to start within 30 seconds"
            docker-compose -f docker-compose.test.yml logs db
            exit 1
        fi
        
        echo -e "${YELLOW}   Attempt $i/30 - waiting...${NC}"
        sleep 2
    done
    
    # Test database connection
    print_info "Testing database connection..."
    if docker-compose -f docker-compose.test.yml exec -T db mysql -udev -pdev -e "SELECT 1" anorm_test >/dev/null 2>&1; then
        print_success "Database connection successful!"
    else
        print_error "Database connection failed"
        exit 1
    fi
}

# Function to build PHP container
build_php_container() {
    print_status "Building PHP container..."
    
    cd "$PROJECT_ROOT"
    
    # Build the PHP container for the specified version
    print_info "Building PHP ${DEFAULT_PHP_VERSION} container..."
    docker-compose -f docker-compose.test.yml build "php${DEFAULT_PHP_VERSION//.}"
    
    print_success "PHP container built successfully"
}

# Function to install dependencies
install_dependencies() {
    print_status "Installing PHP dependencies..."
    
    cd "$PROJECT_ROOT"
    
    # Use docker-compose to run composer install
    print_info "Running composer install..."
    docker-compose -f docker-compose.test.yml run --rm "php${DEFAULT_PHP_VERSION//.}" composer install --no-interaction --prefer-dist
    
    print_success "Dependencies installed successfully"
}

# Function to run tests
run_tests() {
    print_status "Running test verification..."
    
    cd "$PROJECT_ROOT"
    
    # Run quick tests to verify everything works
    print_info "Running quick test suite..."
    
    TEST_OUTPUT=$(docker-compose -f docker-compose.test.yml run --rm "php${DEFAULT_PHP_VERSION//.}" composer test:quick 2>&1 || echo "TESTS_FAILED")
    
    if echo "$TEST_OUTPUT" | grep -q "TESTS_FAILED"; then
        print_warning "Some tests failed, but environment setup is complete"
        print_info "You can investigate test failures by running tests manually"
    else
        print_success "Test verification completed successfully"
    fi
    
    # Show a sample of the test output
    echo
    print_info "Test output sample:"
    echo "$TEST_OUTPUT" | tail -10
}

# Function to create helper scripts
create_helper_scripts() {
    print_status "Creating helper scripts..."
    
    # Create a test runner script
    cat > "$PROJECT_ROOT/run-tests.sh" << 'EOF'
#!/bin/bash
# Helper script to run tests using Docker Compose

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PHP_VERSION="${1:-81}"
TEST_TYPE="${2:-quick}"

echo "Running tests with PHP ${PHP_VERSION} (test type: ${TEST_TYPE})"

case "$TEST_TYPE" in
    "quick")
        docker-compose -f docker-compose.test.yml run --rm "php${PHP_VERSION}" composer test:quick
        ;;
    "coverage")
        docker-compose -f docker-compose.test.yml run --rm "php${PHP_VERSION}" composer test:coverage
        ;;
    "ci")
        docker-compose -f docker-compose.test.yml run --rm "php${PHP_VERSION}" composer test:ci
        ;;
    *)
        docker-compose -f docker-compose.test.yml run --rm "php${PHP_VERSION}" composer test
        ;;
esac
EOF
    
    chmod +x "$PROJECT_ROOT/run-tests.sh"
    
    # Create a development shell script
    cat > "$PROJECT_ROOT/dev-shell.sh" << 'EOF'
#!/bin/bash
# Helper script to get a development shell

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PHP_VERSION="${1:-81}"

echo "Starting development shell with PHP ${PHP_VERSION}"
docker-compose -f docker-compose.test.yml run --rm "php${PHP_VERSION}" bash
EOF
    
    chmod +x "$PROJECT_ROOT/dev-shell.sh"
    
    print_success "Helper scripts created"
}

# Function to display usage information
show_usage() {
    print_status "Environment setup completed successfully!"
    echo
    print_info "Available commands:"
    echo
    echo -e "${CYAN}  # Run tests:${NC}"
    echo "  ./run-tests.sh [php-version] [test-type]"
    echo "    php-version: 74, 80, 81, 82, 83 (default: 81)"
    echo "    test-type: quick, coverage, ci, full (default: quick)"
    echo
    echo -e "${CYAN}  # Examples:${NC}"
    echo "  ./run-tests.sh 81 quick      # Quick tests with PHP 8.1"
    echo "  ./run-tests.sh 82 coverage   # Coverage tests with PHP 8.2"
    echo "  ./run-tests.sh 83 ci         # CI tests with PHP 8.3"
    echo
    echo -e "${CYAN}  # Development shell:${NC}"
    echo "  ./dev-shell.sh [php-version]"
    echo "  ./dev-shell.sh 81            # Shell with PHP 8.1"
    echo
    echo -e "${CYAN}  # Direct docker-compose commands:${NC}"
    echo "  docker-compose -f docker-compose.test.yml run --rm php81 composer test"
    echo "  docker-compose -f docker-compose.test.yml run --rm php82 composer cs:check"
    echo "  docker-compose -f docker-compose.test.yml run --rm php83 composer analyze"
    echo
    echo -e "${CYAN}  # Service management:${NC}"
    echo "  docker-compose -f docker-compose.test.yml up -d db     # Start database"
    echo "  docker-compose -f docker-compose.test.yml down         # Stop all services"
    echo "  docker-compose -f docker-compose.test.yml logs db      # View database logs"
    echo
    echo -e "${CYAN}  # Environment variables:${NC}"
    echo "  DB_HOST=db"
    echo "  DB_DATABASE=anorm_test"
    echo "  DB_USERNAME=dev"
    echo "  DB_PASSWORD=dev"
}

# Main execution
main() {
    echo -e "${PURPLE}🚀 Anorm Docker Compose Environment Setup${NC}"
    echo -e "${PURPLE}===========================================${NC}"
    echo
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    # Run setup steps
    check_docker
    cleanup_existing
    start_services
    build_php_container
    install_dependencies
    run_tests
    create_helper_scripts
    
    echo
    show_usage
    
    echo
    print_success "🎉 Environment setup completed successfully!"
    print_info "Database is running and ready for development!"
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: $0 [options]"
        echo
        echo "Options:"
        echo "  --help, -h     Show this help message"
        echo "  --php-version  Specify PHP version (default: $DEFAULT_PHP_VERSION)"
        echo
        echo "This script sets up the complete development environment using Docker Compose."
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

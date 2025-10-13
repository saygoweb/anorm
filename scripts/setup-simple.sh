#!/bin/bash

# Simple Environment Setup Script for Anorm
# This script provides a minimal setup that works around Docker overlay issues
# Uses system packages where possible

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

# Function to install system dependencies
install_system_deps() {
    print_status "Installing system dependencies..."
    
    # Update package list
    if command_exists apt-get; then
        print_info "Using apt-get to install dependencies..."
        apt-get update
        
        # Install PHP and required extensions
        apt-get install -y \
            php \
            php-cli \
            php-mysql \
            php-pdo \
            php-zip \
            php-xml \
            php-mbstring \
            php-curl \
            php-json \
            unzip \
            curl \
            git \
            mysql-client
        
        print_success "System dependencies installed"
    elif command_exists yum; then
        print_info "Using yum to install dependencies..."
        yum update -y
        yum install -y \
            php \
            php-cli \
            php-mysql \
            php-pdo \
            php-zip \
            php-xml \
            php-mbstring \
            php-curl \
            php-json \
            unzip \
            curl \
            git \
            mysql
        
        print_success "System dependencies installed"
    else
        print_warning "Package manager not found, assuming dependencies are already installed"
    fi
}

# Function to install Composer
install_composer() {
    print_status "Installing Composer..."
    
    if command_exists composer; then
        print_success "Composer already installed"
        return
    fi
    
    # Download and install Composer
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer
    
    print_success "Composer installed successfully"
}

# Function to setup database using a simple MySQL container
setup_simple_database() {
    print_status "Setting up simple MySQL database..."
    
    # Try to use a simpler MySQL image that might not have overlay issues
    print_info "Starting MySQL container..."
    
    # Stop any existing container
    docker stop anorm-simple-db >/dev/null 2>&1 || true
    docker rm -f anorm-simple-db >/dev/null 2>&1 || true
    
    # Try with a different MySQL image
    if docker run -d \
        --name anorm-simple-db \
        -e MYSQL_ROOT_PASSWORD=root \
        -e MYSQL_DATABASE=anorm_test \
        -e MYSQL_USER=dev \
        -e MYSQL_PASSWORD=dev \
        -p 3306:3306 \
        mysql:5.7 \
        --default-authentication-plugin=mysql_native_password; then
        
        print_info "MySQL 5.7 container started successfully"
    else
        print_warning "Failed to start MySQL 5.7, trying MySQL 8.0..."
        
        if docker run -d \
            --name anorm-simple-db \
            -e MYSQL_ROOT_PASSWORD=root \
            -e MYSQL_DATABASE=anorm_test \
            -e MYSQL_USER=dev \
            -e MYSQL_PASSWORD=dev \
            -p 3306:3306 \
            mysql:8.0 \
            --default-authentication-plugin=mysql_native_password; then
            
            print_info "MySQL 8.0 container started successfully"
        else
            print_error "Failed to start MySQL container"
            print_info "Trying alternative approach with SQLite..."
            setup_sqlite_fallback
            return
        fi
    fi
    
    # Wait for database to be ready
    print_info "Waiting for database to be ready..."
    for i in {1..30}; do
        if docker exec anorm-simple-db mysqladmin ping -h localhost --silent 2>/dev/null; then
            print_success "Database is ready!"
            break
        fi
        
        if [ $i -eq 30 ]; then
            print_error "Database failed to start within 30 seconds"
            print_info "Trying SQLite fallback..."
            setup_sqlite_fallback
            return
        fi
        
        echo -e "${YELLOW}   Attempt $i/30 - waiting...${NC}"
        sleep 2
    done
    
    # Test connection
    if docker exec anorm-simple-db mysql -udev -pdev -e "SELECT 1" anorm_test >/dev/null 2>&1; then
        print_success "Database connection successful!"
        
        # Set environment variables
        export DB_HOST=localhost
        export DB_NAME=anorm_test
        export DB_USER=dev
        export DB_PASS=dev
        
        # Create .env file
        cat > "$PROJECT_ROOT/.env" << EOF
DB_HOST=localhost
DB_NAME=anorm_test
DB_USER=dev
DB_PASS=dev
DB_PORT=3306
EOF
        
    else
        print_error "Database connection failed"
        setup_sqlite_fallback
    fi
}

# Function to setup SQLite as fallback
setup_sqlite_fallback() {
    print_warning "Setting up SQLite as database fallback..."
    
    # Install SQLite if not present
    if command_exists apt-get; then
        apt-get install -y sqlite3 php-sqlite3
    elif command_exists yum; then
        yum install -y sqlite php-sqlite3
    fi
    
    # Create SQLite database
    mkdir -p "$PROJECT_ROOT/data"
    touch "$PROJECT_ROOT/data/anorm_test.sqlite"
    
    # Set environment variables for SQLite
    export DB_HOST=sqlite
    export DB_NAME="$PROJECT_ROOT/data/anorm_test.sqlite"
    export DB_USER=""
    export DB_PASS=""
    
    # Create .env file for SQLite
    cat > "$PROJECT_ROOT/.env" << EOF
DB_HOST=sqlite
DB_NAME=$PROJECT_ROOT/data/anorm_test.sqlite
DB_USER=
DB_PASS=
EOF
    
    print_success "SQLite database setup completed"
}

# Function to install PHP dependencies
install_php_dependencies() {
    print_status "Installing PHP dependencies..."
    
    cd "$PROJECT_ROOT"
    
    if command_exists composer; then
        composer install --no-interaction --prefer-dist
        print_success "PHP dependencies installed"
    else
        print_error "Composer not found"
        exit 1
    fi
}

# Function to run basic tests
run_basic_tests() {
    print_status "Running basic test verification..."
    
    cd "$PROJECT_ROOT"
    
    # Check if we can run PHP
    if php --version >/dev/null 2>&1; then
        print_success "PHP is working"
    else
        print_error "PHP is not working"
        return 1
    fi
    
    # Try to run a simple test
    print_info "Running quick test verification..."
    
    if composer test:quick >/dev/null 2>&1; then
        print_success "Tests are working!"
    else
        print_warning "Some tests failed, but environment is set up"
        print_info "You can run 'composer test' to see detailed results"
    fi
}

# Function to create simple helper scripts
create_simple_helpers() {
    print_status "Creating helper scripts..."
    
    # Create a simple test runner
    cat > "$PROJECT_ROOT/test.sh" << 'EOF'
#!/bin/bash
# Simple test runner

set -e

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

# Run tests
composer test:quick
EOF
    
    chmod +x "$PROJECT_ROOT/test.sh"
    
    # Create environment loader
    cat > "$PROJECT_ROOT/load-env.sh" << 'EOF'
#!/bin/bash
# Load environment variables

if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
    echo "Environment variables loaded from .env"
    echo "DB_HOST=$DB_HOST"
    echo "DB_NAME=$DB_NAME"
    echo "DB_USER=$DB_USER"
else
    echo "No .env file found"
fi
EOF
    
    chmod +x "$PROJECT_ROOT/load-env.sh"
    
    print_success "Helper scripts created"
}

# Function to display usage
show_simple_usage() {
    print_status "Simple environment setup completed!"
    echo
    print_info "Available commands:"
    echo
    echo -e "${CYAN}  # Load environment and run tests:${NC}"
    echo "  source load-env.sh && composer test"
    echo "  ./test.sh                    # Quick test runner"
    echo
    echo -e "${CYAN}  # Manual testing:${NC}"
    echo "  source load-env.sh           # Load environment variables"
    echo "  composer test:quick          # Quick tests"
    echo "  composer test:coverage       # Tests with coverage"
    echo
    echo -e "${CYAN}  # Code quality:${NC}"
    echo "  composer cs:check            # Check coding standards"
    echo "  composer analyze             # Static analysis"
    echo
    if [ "$DB_HOST" = "localhost" ]; then
        echo -e "${CYAN}  # Database management:${NC}"
        echo "  docker stop anorm-simple-db  # Stop database"
        echo "  docker start anorm-simple-db # Start database"
        echo "  docker rm -f anorm-simple-db # Remove database"
    fi
    echo
    echo -e "${CYAN}  # Environment file:${NC}"
    echo "  cat .env                     # View current configuration"
}

# Main execution
main() {
    echo -e "${PURPLE}🚀 Anorm Simple Environment Setup${NC}"
    echo -e "${PURPLE}==================================${NC}"
    echo
    
    cd "$PROJECT_ROOT"
    
    # Check if running as root (needed for package installation)
    if [ "$EUID" -ne 0 ] && command_exists apt-get; then
        print_warning "This script may need root privileges to install system packages"
        print_info "You may need to run: sudo $0"
    fi
    
    # Run setup steps
    install_system_deps
    install_composer
    setup_simple_database
    install_php_dependencies
    run_basic_tests
    create_simple_helpers
    
    echo
    show_simple_usage
    
    echo
    print_success "🎉 Simple environment setup completed!"
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: $0 [options]"
        echo
        echo "This script provides a simple environment setup that works around"
        echo "Docker overlay filesystem issues by using system packages and"
        echo "simpler container configurations."
        exit 0
        ;;
esac

# Run main function
main "$@"

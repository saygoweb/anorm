#!/bin/bash

# Anorm Test Environment Setup Script (Idempotent)
# This script installs all necessary dependencies for running composer test
# Excludes Docker and SQLite - uses local PHP and MariaDB only
# Can be run multiple times safely - will only install what's missing

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
PROJECT_ROOT="$SCRIPT_DIR"

# Load environment variables from .env file
load_env_config() {
    if [ -f "$PROJECT_ROOT/.env" ]; then
        print_info "Loading configuration from .env file..."
        # Export variables from .env file
        set -a
        source "$PROJECT_ROOT/.env"
        set +a
        print_success "Configuration loaded from .env"
    else
        print_error ".env file not found in $PROJECT_ROOT"
        print_info "Please ensure .env file exists with database configuration"
        exit 1
    fi
}

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

# Function to check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script needs to be run as root to install system packages"
        print_info "Please run: sudo $0"
        exit 1
    fi
}

# Function to install system dependencies (idempotent)
install_system_deps() {
    print_status "Checking system dependencies..."

    local packages_to_install=()
    local required_packages=(
        "php"
        "php-cli"
        "php-mysql"
        "php-pdo"
        "php-zip"
        "php-xml"
        "php-mbstring"
        "php-curl"
        "php-json"
        "unzip"
        "curl"
        "git"
    )

    # Check which packages are missing
    for package in "${required_packages[@]}"; do
        if ! dpkg -l | grep -q "^ii  $package "; then
            packages_to_install+=("$package")
        fi
    done

    if [ ${#packages_to_install[@]} -eq 0 ]; then
        print_success "All system dependencies already installed"
        return
    fi

    print_info "Installing missing packages: ${packages_to_install[*]}"

    # Update package list only if we need to install something
    apt-get update

    # Install missing packages
    apt-get install -y "${packages_to_install[@]}"

    print_success "System dependencies installed"
}

# Function to install Composer (idempotent)
install_composer() {
    print_status "Checking Composer installation..."

    if command_exists composer; then
        local composer_version=$(composer --version 2>/dev/null | head -n1)
        print_success "Composer already installed: $composer_version"
        return
    fi

    print_info "Installing Composer..."
    # Download and install Composer
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer

    print_success "Composer installed successfully"
}

# Function to install MariaDB (idempotent)
install_mariadb() {
    print_status "Checking MariaDB installation..."

    # Check if MariaDB is already installed
    if dpkg -l | grep -q "^ii  mariadb-server "; then
        print_success "MariaDB server already installed"
        return
    fi

    print_info "Installing MariaDB server..."

    # Set non-interactive mode for MariaDB installation
    export DEBIAN_FRONTEND=noninteractive

    # Pre-configure MariaDB root password
    debconf-set-selections <<< "mariadb-server mysql-server/root_password password $DB_ROOT_PASSWORD"
    debconf-set-selections <<< "mariadb-server mysql-server/root_password_again password $DB_ROOT_PASSWORD"

    # Install MariaDB server
    apt-get install -y mariadb-server mariadb-client

    print_success "MariaDB server installed"
}

# Function to ensure MariaDB is running and configured (idempotent)
ensure_mariadb_running() {
    print_status "Ensuring MariaDB is running and configured..."

    # Check if MariaDB is already running
    if mysqladmin ping -h localhost --silent 2>/dev/null; then
        print_success "MariaDB is already running"
    else
        print_info "Starting MariaDB server..."

        # Initialize MariaDB data directory if needed
        if [ ! -d "/var/lib/mysql/mysql" ]; then
            print_info "Initializing MariaDB data directory..."
            mysql_install_db --user=mysql --datadir=/var/lib/mysql
        fi

        # Kill any existing MariaDB processes
        pkill -f mysqld || true
        sleep 2

        # Start MariaDB in the background
        mysqld_safe --user=mysql --datadir=/var/lib/mysql --socket=/var/run/mysqld/mysqld.sock --pid-file=/var/run/mysqld/mysqld.pid &

        # Wait for MariaDB to be ready
        print_info "Waiting for MariaDB to start..."
        for i in {1..30}; do
            if mysqladmin ping -h localhost --silent 2>/dev/null; then
                print_success "MariaDB is running!"
                break
            fi

            if [ $i -eq 30 ]; then
                print_error "MariaDB failed to start within 30 seconds"
                print_info "Checking MariaDB logs..."
                tail -20 /var/log/mysql/error.log 2>/dev/null || echo "No error log found"
                exit 1
            fi

            sleep 1
        done
    fi

    # Check if test database and user exist
    print_info "Verifying database configuration..."

    # Test if we can connect with the test user
    if mysql -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
        print_success "Database and user already configured correctly"
        return
    fi

    print_info "Configuring database and user..."

    # Try to connect as root and set up database/user
    if mysql -u root -p"$DB_ROOT_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; then
        # Root password is already set, use it
        mysql -u root -p"$DB_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    else
        # Try without password first (fresh install)
        mysql -u root <<EOF
SET PASSWORD FOR 'root'@'localhost' = PASSWORD('$DB_ROOT_PASSWORD');
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    fi

    # Final connection test
    if mysql -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
        print_success "Database configuration completed successfully!"
    else
        print_error "Database connection test failed"
        exit 1
    fi
}

# Function to install PHP dependencies (idempotent)
install_php_dependencies() {
    print_status "Checking PHP dependencies..."

    cd "$PROJECT_ROOT"

    # Check if vendor directory exists and composer.lock is newer than composer.json
    if [ -d "vendor" ] && [ -f "composer.lock" ] && [ "composer.lock" -nt "composer.json" ]; then
        print_success "PHP dependencies already installed and up to date"
        return
    fi

    print_info "Installing/updating PHP dependencies..."

    # Change ownership to the original user if running as root
    if [ -n "$SUDO_USER" ]; then
        chown -R "$SUDO_USER:$SUDO_USER" "$PROJECT_ROOT"
        sudo -u "$SUDO_USER" composer install --no-interaction --prefer-dist
    else
        composer install --no-interaction --prefer-dist
    fi

    print_success "PHP dependencies installed"
}

# Function to setup environment for calling shell
setup_calling_shell_environment() {
    print_status "Setting up environment for calling shell..."

    # Create a temporary script that will export environment variables
    local env_script="/tmp/anorm_env_$$"

    cat > "$env_script" << EOF
# Anorm Environment Variables
# Source this file to load environment variables into your shell
# Usage: source $env_script

if [ -f "$PROJECT_ROOT/.env" ]; then
    set -a
    source "$PROJECT_ROOT/.env"
    set +a
    echo "✅ Environment variables loaded from .env"
    echo "   DB_HOST=\$DB_HOST"
    echo "   DB_NAME=\$DB_NAME"
    echo "   DB_USER=\$DB_USER"
    echo ""
    echo "🎉 You can now run 'composer test' directly!"
else
    echo "❌ Error: .env file not found at $PROJECT_ROOT/.env"
fi
EOF

    # Make the script readable by the original user
    if [ -n "$SUDO_USER" ]; then
        chown "$SUDO_USER:$SUDO_USER" "$env_script"
    fi

    print_success "Environment setup script created at: $env_script"
    print_info "To load environment variables into your current shell, run:"
    print_info "  source $env_script"

    # Store the script path for later use
    ENV_SCRIPT_PATH="$env_script"
}

# Function to verify environment is working (idempotent)
verify_environment() {
    print_status "Verifying environment setup..."

    cd "$PROJECT_ROOT"

    # Test database connection first
    print_info "Testing database connection..."
    if mysql -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1 as test" "$DB_NAME" >/dev/null 2>&1; then
        print_success "Database connection successful"
    else
        print_error "Database connection failed"
        print_info "Please check database configuration in .env file"
        return 1
    fi

    # Test PHP and required extensions
    print_info "Testing PHP configuration..."
    local required_extensions=("PDO" "pdo_mysql" "zip" "xml" "mbstring" "curl" "json")
    local missing_extensions=()

    for ext in "${required_extensions[@]}"; do
        # Use case-insensitive grep and check for both exact match and partial match
        if ! php -m | grep -qi "$ext"; then
            missing_extensions+=("$ext")
        fi
    done

    if [ ${#missing_extensions[@]} -gt 0 ]; then
        print_error "Missing PHP extensions: ${missing_extensions[*]}"
        return 1
    else
        print_success "All required PHP extensions are available"
    fi

    # Test Composer
    if ! command_exists composer; then
        print_error "Composer not found"
        return 1
    else
        print_success "Composer is available"
    fi

    # Export environment variables for the current session
    print_info "Exporting environment variables for tests..."
    export DB_HOST="$DB_HOST"
    export DB_NAME="$DB_NAME"
    export DB_USER="$DB_USER"
    export DB_PASS="$DB_PASS"
    export DB_DATABASE="$DB_NAME"
    export DB_USERNAME="$DB_USER"
    export DB_PASSWORD="$DB_PASS"

    # Run a quick test to verify everything works
    print_info "Running quick test verification..."

    if [ -n "$SUDO_USER" ]; then
        # Run as the original user with environment loaded
        if sudo -u "$SUDO_USER" -E bash -c "composer test:quick" >/dev/null 2>&1; then
            print_success "Test verification passed!"
        else
            print_warning "Test verification had issues, but environment setup is complete"
            print_info "You can run 'composer test' manually to see detailed results"
        fi
    else
        # Run tests with exported environment
        if composer test:quick >/dev/null 2>&1; then
            print_success "Test verification passed!"
        else
            print_warning "Test verification had issues, but environment setup is complete"
            print_info "You can run 'composer test' manually to see detailed results"
        fi
    fi

    print_success "Environment verification completed!"
}

# Function to display usage information
show_usage() {
    print_status "Test environment ready!"
    echo
    print_info "Configuration (from .env file):"
    echo "  Host: $DB_HOST"
    echo "  Database: $DB_NAME"
    echo "  Username: $DB_USER"
    echo "  Password: $DB_PASS"
    echo "  Root Password: $DB_ROOT_PASSWORD"
    echo
    print_info "To load environment variables and run tests:"
    echo
    echo -e "${CYAN}  # Load environment into your shell:${NC}"
    if [ -n "$ENV_SCRIPT_PATH" ]; then
        echo "  source $ENV_SCRIPT_PATH"
    else
        echo "  source /tmp/anorm_env_*"
    fi
    echo
    echo -e "${CYAN}  # Then run tests directly:${NC}"
    echo "  composer test                # Full test suite"
    echo "  composer test:quick          # Quick tests without coverage"
    echo "  composer test:coverage       # Tests with coverage"
    echo "  composer test:ci             # CI tests with clover coverage"
    echo
    echo -e "${CYAN}  # Code quality:${NC}"
    echo "  composer cs:check            # Check coding standards"
    echo "  composer cs:fix              # Fix coding standards"
    echo "  composer analyze             # Static analysis"
    echo "  composer quality             # Run all quality checks"
    echo
    echo -e "${CYAN}  # Database management:${NC}"
    echo "  sudo pkill -f mysqld           # Stop MariaDB"
    echo "  sudo mysqld_safe --user=mysql --datadir=/var/lib/mysql &  # Start MariaDB"
    echo "  mysqladmin ping -h localhost   # Check MariaDB status"
    echo "  mysql -u$DB_USER -p$DB_PASS $DB_NAME  # Connect to test database"
    echo
    echo -e "${CYAN}  # Environment:${NC}"
    echo "  cat .env                     # View environment configuration"
    echo "  sudo ./setup-test-environment.sh  # Re-run setup (idempotent)"
}

# Main execution
main() {
    echo -e "${PURPLE}🚀 Anorm Test Environment Setup (Idempotent)${NC}"
    echo -e "${PURPLE}=============================================${NC}"
    echo

    # Check if running as root
    check_root

    # Change to project root
    cd "$PROJECT_ROOT"

    # Load configuration from .env file
    load_env_config

    # Run setup steps (all idempotent)
    install_system_deps
    install_composer
    install_mariadb
    ensure_mariadb_running
    install_php_dependencies
    setup_calling_shell_environment
    verify_environment

    echo
    show_usage

    echo
    print_success "🎉 Test environment setup completed successfully!"
    echo
    print_info "📋 To load environment variables into your current shell, run:"
    echo
    echo -e "${GREEN}# Copy and paste this command:${NC}"
    echo -e "${CYAN}export DB_HOST='$DB_HOST' DB_NAME='$DB_NAME' DB_USER='$DB_USER' DB_PASS='$DB_PASS' DB_DATABASE='$DB_NAME' DB_USERNAME='$DB_USER' DB_PASSWORD='$DB_PASS'${NC}"
    echo
    print_info "Or alternatively:"
    if [ -n "$ENV_SCRIPT_PATH" ]; then
        echo -e "${CYAN}source $ENV_SCRIPT_PATH${NC}"
    else
        echo -e "${CYAN}source /tmp/anorm_env_*${NC}"
    fi
    echo
    print_info "Then run: ${CYAN}composer test${NC}"
    echo
    print_info "Run this script again anytime to ensure environment is properly configured."
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: sudo $0 [options]"
        echo
        echo "This script installs all necessary dependencies for running"
        echo "composer test in the Anorm project."
        echo
        echo "Features:"
        echo "  - Idempotent: Can be run multiple times safely"
        echo "  - Loads configuration from .env file"
        echo "  - Only installs missing components"
        echo "  - Ensures database is running and accessible"
        echo
        echo "Options:"
        echo "  --env-only    Only output environment variable export commands"
        echo
        echo "Requirements:"
        echo "  - Must be run as root (use sudo)"
        echo "  - Ubuntu/Debian system with apt package manager"
        echo "  - .env file must exist with database configuration"
        echo
        echo "Examples:"
        echo "  sudo $0                    # Full setup"
        echo "  eval \"\$(sudo $0 --env-only)\"  # Load environment variables"
        exit 0
        ;;
    --env-only)
        # Just load config and output export commands
        cd "$PROJECT_ROOT"
        load_env_config >/dev/null 2>&1
        echo "export DB_HOST='$DB_HOST' DB_NAME='$DB_NAME' DB_USER='$DB_USER' DB_PASS='$DB_PASS' DB_DATABASE='$DB_NAME' DB_USERNAME='$DB_USER' DB_PASSWORD='$DB_PASS'"
        exit 0
        ;;
esac

# Run main function
main "$@"

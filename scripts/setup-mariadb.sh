#!/bin/bash

# MariaDB Environment Setup Script for Anorm
# This script installs MariaDB server locally and sets up the test environment

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
DB_ROOT_PASSWORD="root"
DB_NAME="anorm_test"
DB_USER="dev"
DB_PASSWORD="dev"

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

# Function to install system dependencies
install_system_deps() {
    print_status "Installing system dependencies..."
    
    # Update package list
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
        git
    
    print_success "System dependencies installed"
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

# Function to install MariaDB
install_mariadb() {
    print_status "Installing MariaDB server..."
    
    # Set non-interactive mode for MariaDB installation
    export DEBIAN_FRONTEND=noninteractive
    
    # Pre-configure MariaDB root password
    debconf-set-selections <<< "mariadb-server mysql-server/root_password password $DB_ROOT_PASSWORD"
    debconf-set-selections <<< "mariadb-server mysql-server/root_password_again password $DB_ROOT_PASSWORD"
    
    # Install MariaDB server
    apt-get install -y mariadb-server mariadb-client
    
    print_success "MariaDB server installed"
}

# Function to configure MariaDB
configure_mariadb() {
    print_status "Configuring MariaDB..."

    # Initialize MariaDB data directory if needed
    if [ ! -d "/var/lib/mysql/mysql" ]; then
        print_info "Initializing MariaDB data directory..."
        mysql_install_db --user=mysql --datadir=/var/lib/mysql
    fi

    # Start MariaDB manually (since systemd might not be available)
    print_info "Starting MariaDB server..."

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

    # Set root password and create test database and user
    print_info "Setting up root password and creating test database..."

    # First, set the root password
    mysql -u root <<EOF
SET PASSWORD FOR 'root'@'localhost' = PASSWORD('$DB_ROOT_PASSWORD');
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

    # Test the connection
    if mysql -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
        print_success "Database connection test successful!"
    else
        print_error "Database connection test failed"
        exit 1
    fi

    print_success "MariaDB configuration completed"
}

# Function to install PHP dependencies
install_php_dependencies() {
    print_status "Installing PHP dependencies..."
    
    cd "$PROJECT_ROOT"
    
    # Change ownership to the original user if running as root
    if [ -n "$SUDO_USER" ]; then
        chown -R "$SUDO_USER:$SUDO_USER" "$PROJECT_ROOT"
        sudo -u "$SUDO_USER" composer install --no-interaction --prefer-dist
    else
        composer install --no-interaction --prefer-dist
    fi
    
    print_success "PHP dependencies installed"
}

# Function to setup environment variables
setup_environment() {
    print_status "Setting up environment variables..."
    
    # Create .env file
    cat > "$PROJECT_ROOT/.env" << EOF
# Database configuration for testing
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASSWORD
DB_PORT=3306

# Alternative variable names for compatibility
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD
EOF
    
    # Change ownership if running as root
    if [ -n "$SUDO_USER" ]; then
        chown "$SUDO_USER:$SUDO_USER" "$PROJECT_ROOT/.env"
    fi
    
    print_success "Environment variables configured"
    print_info "Configuration saved to .env file"
}

# Function to run tests
run_tests() {
    print_status "Running test verification..."
    
    cd "$PROJECT_ROOT"
    
    # Export environment variables
    export DB_HOST=localhost
    export DB_NAME="$DB_NAME"
    export DB_USER="$DB_USER"
    export DB_PASS="$DB_PASSWORD"
    export DB_DATABASE="$DB_NAME"
    export DB_USERNAME="$DB_USER"
    export DB_PASSWORD="$DB_PASSWORD"
    
    print_info "Running quick test suite..."
    
    if [ -n "$SUDO_USER" ]; then
        # Run as the original user
        sudo -u "$SUDO_USER" -E composer test:quick
    else
        composer test:quick
    fi
    
    print_success "Test verification completed successfully!"
}

# Function to create helper scripts
create_helper_scripts() {
    print_status "Creating helper scripts..."
    
    # Create a test runner script
    cat > "$PROJECT_ROOT/run-tests.sh" << 'EOF'
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
EOF
    
    chmod +x "$PROJECT_ROOT/run-tests.sh"
    
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
    
    # Change ownership if running as root
    if [ -n "$SUDO_USER" ]; then
        chown "$SUDO_USER:$SUDO_USER" "$PROJECT_ROOT/run-tests.sh"
        chown "$SUDO_USER:$SUDO_USER" "$PROJECT_ROOT/load-env.sh"
    fi
    
    print_success "Helper scripts created"
}

# Function to display usage information
show_usage() {
    print_status "MariaDB environment setup completed successfully!"
    echo
    print_info "Database Information:"
    echo "  Host: localhost"
    echo "  Database: $DB_NAME"
    echo "  Username: $DB_USER"
    echo "  Password: $DB_PASSWORD"
    echo "  Root Password: $DB_ROOT_PASSWORD"
    echo
    print_info "Available commands:"
    echo
    echo -e "${CYAN}  # Run tests:${NC}"
    echo "  ./run-tests.sh [quick|coverage|ci|full]"
    echo "  source load-env.sh && composer test"
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
    echo "  mysql -u$DB_USER -p$DB_PASSWORD $DB_NAME  # Connect to test database"
    echo
    echo -e "${CYAN}  # Environment:${NC}"
    echo "  source load-env.sh           # Load environment variables"
    echo "  cat .env                     # View environment configuration"
}

# Main execution
main() {
    echo -e "${PURPLE}🚀 Anorm MariaDB Environment Setup${NC}"
    echo -e "${PURPLE}===================================${NC}"
    echo
    
    # Check if running as root
    check_root
    
    # Change to project root
    cd "$PROJECT_ROOT"
    
    # Run setup steps
    install_system_deps
    install_composer
    install_mariadb
    configure_mariadb
    install_php_dependencies
    setup_environment
    create_helper_scripts
    run_tests
    
    echo
    show_usage
    
    echo
    print_success "🎉 MariaDB environment setup completed successfully!"
    print_info "You can now run tests and start developing!"
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: sudo $0 [options]"
        echo
        echo "This script installs MariaDB server locally and sets up the"
        echo "complete development environment for Anorm."
        echo
        echo "Requirements:"
        echo "  - Must be run as root (use sudo)"
        echo "  - Ubuntu/Debian system with apt package manager"
        exit 0
        ;;
esac

# Run main function
main "$@"

# Developer Notes

## Development Workflow

This project uses Composer scripts for development tasks and GitHub Actions for CI/CD.

## Running Tests

### Quick Tests (no coverage)
```bash
composer test:quick
```

### Full Test Suite
```bash
composer test
```

### Tests with Coverage
```bash
composer test:coverage
```

### CI Tests (with clover coverage)
```bash
composer test:ci
```

## Code Quality

### Check Code Style
```bash
composer cs:check
```

### Fix Code Style Issues
```bash
composer cs:fix
```

### Static Analysis
```bash
composer analyze
```

### Run All Quality Checks
```bash
composer quality
```

### Run Full CI Suite
```bash
composer ci
```

## Development Environment

### Using DevContainer
This project includes a DevContainer configuration for VS Code. Open the project in VS Code and select "Reopen in Container" when prompted.

### Manual Setup
1. Install PHP 7.4+ with extensions: `pdo`, `pdo_mysql`, `zip`, `xdebug`
2. Install Composer
3. Run `composer install`
4. Set up a MySQL/MariaDB database named `anorm_test`
5. Configure database connection via environment variables:
   - `DB_HOST` (default: localhost)
   - `DB_DATABASE` (default: anorm_test)
   - `DB_USERNAME` (default: dev)
   - `DB_PASSWORD` (default: dev)

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Make your changes
4. Run the full test suite: `composer ci`
5. Commit your changes: `git commit -am 'Add some feature'`
6. Push to the branch: `git push origin feature/your-feature`
7. Submit a pull request

## CI/CD Pipeline

The project uses GitHub Actions for continuous integration:

- **CI Workflow**: Runs tests on multiple PHP versions (7.4, 8.0, 8.1, 8.2, 8.3)
- **Code Quality**: Runs PHPStan, PHP CodeSniffer, and security scans
- **Coverage**: Generates coverage reports and uploads to Coveralls
- **Artifacts**: Coverage reports are available as downloadable artifacts

### Coverage Requirements
- Minimum line coverage: 80%
- Coverage reports are generated in HTML format and uploaded as artifacts
- Pull requests receive automated coverage comments

## Legacy Build System

**Note**: The legacy `fred.php` build system has been replaced with Composer scripts. If you have `fred` installed globally, the equivalent commands are:

- `fred test` → `composer test`
- `fred test-coverage` → `composer test:coverage`
- `fred test-quick` → `composer test:quick`
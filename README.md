# Anorm: Another ORM for PHP

[![CI](https://github.com/saygoweb/anorm/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/saygoweb/anorm/actions/workflows/ci.yml?query=branch%3Amaster)
[![Code Quality](https://github.com/saygoweb/anorm/actions/workflows/quality.yml/badge.svg?branch=master)](https://github.com/saygoweb/anorm/actions/workflows/quality.yml?query=branch%3Amaster)
[![Coverage Status](https://coveralls.io/repos/github/saygoweb/anorm/badge.svg?branch=master)](https://coveralls.io/github/saygoweb/anorm?branch=master)
[![MIT Licence](https://badges.frapsoft.com/os/mit/mit.svg?v=103)](https://opensource.org/licenses/mit-license.php)

Yes, yet another ORM for PHP. This meets my needs for an ORM with the following characteristics:

* Works well with legacy databases.
* Provides (requires) a Model class which helps coding in IDEs.
* Creates and modifies the underlying database schema as required to match the Model.

## Features

* Provides a tool 'anorm' for quickly generating models from existing tables.
* Maps between camelCase property names and under_score field names common in database schema.
* Makes CRUD operations extremely simple.
* Doesn't get in the way of complex queries.

## Documentation

Documentation is available on the [docs site](https://saygoweb.github.io/anorm).

## Development

### Quick Start
```bash
# Install dependencies
composer install

# Run tests
composer test

# Run tests with coverage
composer test:coverage

# Check code quality
composer quality
```

### Requirements
- PHP 7.4+ (tested on 7.4, 8.0, 8.1, 8.2, 8.3)
- MySQL/MariaDB
- Composer

### Available Commands
- `composer test` - Run full test suite
- `composer test:quick` - Run tests without coverage
- `composer test:coverage` - Run tests with HTML coverage report
- `composer test:ci` - Run tests with clover coverage for CI
- `composer cs:check` - Check code style (PSR-12)
- `composer cs:fix` - Fix code style issues
- `composer analyze` - Run static analysis (PHPStan)
- `composer quality` - Run all quality checks
- `composer ci` - Run full CI suite (tests + quality)

See [DEVELOPERS.md](DEVELOPERS.md) for detailed development instructions.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run `composer ci` to ensure all tests pass and code quality is maintained
5. Submit a pull request

All pull requests are automatically tested on multiple PHP versions with comprehensive code quality checks.
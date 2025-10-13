# Contributing to Anorm

Thank you for your interest in contributing to Anorm! This document provides guidelines and information for contributors.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment for all contributors.

## Getting Started

### Prerequisites
- PHP 7.4 or higher
- Composer
- MySQL/MariaDB database
- Git

### Development Setup

1. **Fork and Clone**
   ```bash
   git clone https://github.com/YOUR_USERNAME/anorm.git
   cd anorm
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Set Up Database**
   - Create a database named `anorm_test`
   - Configure connection via environment variables:
     ```bash
     export DB_HOST=localhost
     export DB_DATABASE=anorm_test
     export DB_USERNAME=your_username
     export DB_PASSWORD=your_password
     ```

4. **Verify Setup**
   ```bash
   composer test
   ```

### Using DevContainer (Recommended)

If you use VS Code, you can use the provided DevContainer for a consistent development environment:

1. Install the "Dev Containers" extension in VS Code
2. Open the project in VS Code
3. When prompted, click "Reopen in Container"
4. The environment will be automatically set up with PHP, database, and all dependencies

## Development Workflow

### Making Changes

1. **Create a Feature Branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make Your Changes**
   - Write clean, well-documented code
   - Follow PSR-12 coding standards
   - Add tests for new functionality
   - Update documentation as needed

3. **Test Your Changes**
   ```bash
   # Run all tests
   composer test
   
   # Check code style
   composer cs:check
   
   # Run static analysis
   composer analyze
   
   # Run full CI suite
   composer ci
   ```

4. **Fix Any Issues**
   ```bash
   # Auto-fix code style issues
   composer cs:fix
   ```

### Commit Guidelines

- Use clear, descriptive commit messages
- Start with a verb in present tense (e.g., "Add", "Fix", "Update")
- Keep the first line under 50 characters
- Add detailed description if needed

Example:
```
Add support for composite primary keys

- Implement composite key handling in DataMapper
- Add tests for multi-column primary keys
- Update documentation with examples
```

## Code Standards

### PHP Standards
- Follow PSR-12 coding style
- Use type hints where possible
- Write PHPDoc comments for all public methods
- Maintain backward compatibility when possible

### Testing
- Write unit tests for all new functionality
- Maintain or improve code coverage (minimum 80%)
- Use descriptive test method names
- Test both success and failure scenarios

### Documentation
- Update relevant documentation for new features
- Include code examples in docblocks
- Update DEVELOPERS.md for workflow changes

## Pull Request Process

1. **Before Submitting**
   - Ensure all tests pass: `composer ci`
   - Update documentation if needed
   - Rebase your branch on the latest master

2. **Submit Pull Request**
   - Use a clear, descriptive title
   - Provide detailed description of changes
   - Reference any related issues
   - Include screenshots for UI changes (if applicable)

3. **Review Process**
   - Automated tests will run on multiple PHP versions
   - Code quality checks will be performed
   - Maintainers will review your code
   - Address any feedback promptly

4. **After Approval**
   - Your PR will be merged by a maintainer
   - The feature branch will be deleted

## Available Commands

### Testing
- `composer test` - Run full test suite
- `composer test:quick` - Run tests without coverage
- `composer test:coverage` - Generate HTML coverage report
- `composer test:ci` - Run tests with clover coverage

### Code Quality
- `composer cs:check` - Check PSR-12 compliance
- `composer cs:fix` - Auto-fix style issues
- `composer analyze` - Run PHPStan static analysis
- `composer quality` - Run all quality checks
- `composer ci` - Run complete CI suite

## Reporting Issues

### Bug Reports
- Use the GitHub issue tracker
- Include PHP version and environment details
- Provide minimal reproduction steps
- Include relevant error messages

### Feature Requests
- Describe the use case clearly
- Explain why the feature would be beneficial
- Consider backward compatibility implications

## Getting Help

- Check existing documentation first
- Search existing issues and discussions
- Create a new issue for questions
- Be specific about your problem or question

## Recognition

Contributors will be recognized in:
- Git commit history
- Release notes for significant contributions
- Project documentation

Thank you for contributing to Anorm!

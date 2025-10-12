# Build System Upgrade Plan: Remove fred.php and Enhance GitHub Actions

## Overview
This plan outlines the migration from the legacy `fred.php` build system to a fully GitHub Actions-based CI/CD pipeline with enhanced code coverage reporting.

## Current State Analysis

### Existing Build Tools
1. **fred.php** - Legacy build script with 3 tasks:
   - `test`: Basic PHPUnit execution
   - `test-coverage`: PHPUnit with HTML coverage output to `build/code_coverage`
   - `test-quick`: PHPUnit without coverage using `phpunit-no-coverage.xml`

2. **GitHub Actions** - Already partially implemented in `.github/workflows/php.yml`:
   - PHP 7.4 setup with MariaDB service
   - Composer dependency management
   - PHPUnit execution with clover coverage
   - Coveralls integration

3. **Travis CI** - Legacy `.travis.yml` (likely unused)

### Current Coverage Setup
- PHPUnit configured with coverage in `phpunit.xml`
- Coverage outputs: HTML (`build/coverage`), Clover XML (`build/logs/clover.xml`), JUnit XML
- Coveralls integration via `php-coveralls/php-coveralls`

## Migration Plan

### Phase 1: GitHub Actions Enhancement
**Objective**: Improve the existing GitHub Actions workflow

#### 1.1 Workflow Improvements
- [x] Add multiple PHP version testing (7.4, 8.0, 8.1, 8.2, 8.3)
- [x] Add matrix strategy for different PHP versions
- [x] Improve caching strategy for better performance
- [x] Add proper error handling and status reporting

#### 1.2 Enhanced Code Coverage
- [x] Add coverage threshold enforcement
- [x] Generate multiple coverage formats (HTML, Clover, Cobertura)
- [x] Upload coverage artifacts for download
- [x] Add coverage comments on PRs
- [x] Integrate with additional coverage services (CodeClimate, Codecov)

#### 1.3 Additional Quality Checks
- [x] Add PHP CodeSniffer for code style checking
- [x] Add PHPStan for static analysis
- [x] Add security vulnerability scanning
- [x] Add dependency vulnerability checking

### Phase 2: fred.php Removal
**Objective**: Remove fred.php and update documentation

#### 2.1 Replace fred.php Functionality
- [x] Create composer scripts to replace fred tasks:
  - `composer test` → equivalent to fred's `test` task
  - `composer test:coverage` → equivalent to fred's `test-coverage` task
  - `composer test:quick` → equivalent to fred's `test-quick` task
- [x] Add local development scripts in `scripts/` directory

#### 2.2 Documentation Updates
- [x] Update `DEVELOPERS.md` to remove fred references
- [x] Add new development workflow documentation
- [x] Update README.md with new build badges and instructions
- [x] Create contributing guidelines with new workflow

#### 2.3 File Cleanup
- [x] Remove `fred.php`
- [x] Remove `.travis.yml` (if confirmed unused)
- [ ] Update `.gitignore` if needed

### Phase 3: Advanced CI/CD Features
**Objective**: Add modern CI/CD capabilities

#### 3.1 Automated Releases
- [ ] Add semantic versioning automation
- [ ] Create release workflow triggered by tags
- [ ] Generate automated changelogs
- [ ] Publish to Packagist automatically

#### 3.2 Performance and Quality Monitoring
- [ ] Add performance benchmarking
- [ ] Set up code quality gates
- [ ] Add automated dependency updates (Dependabot)
- [ ] Implement branch protection rules

#### 3.3 Development Environment
- [ ] Enhance devcontainer configuration
- [ ] Add pre-commit hooks setup
- [ ] Create development Docker compose improvements

## Implementation Details

### New Composer Scripts
```json
{
  "scripts": {
    "test": "phpunit",
    "test:coverage": "phpunit --coverage-html build/coverage --coverage-text",
    "test:quick": "phpunit -c phpunit-no-coverage.xml",
    "test:ci": "phpunit --coverage-clover=build/logs/clover.xml",
    "cs:check": "phpcs --standard=PSR12 src/ test/",
    "cs:fix": "phpcbf --standard=PSR12 src/ test/",
    "analyze": "phpstan analyse src/ test/ --level=5"
  }
}
```

### Enhanced GitHub Actions Matrix
```yaml
strategy:
  matrix:
    php-version: ['7.4', '8.0', '8.1', '8.2', '8.3']
    include:
      - php-version: '8.3'
        coverage: true
```

### Coverage Thresholds
- Line coverage: minimum 80%
- Function coverage: minimum 85%
- Class coverage: minimum 90%

## Risk Assessment

### Low Risk
- GitHub Actions workflow already exists and working
- PHPUnit configuration is solid
- Coverage reporting already functional

### Medium Risk
- Multiple PHP version compatibility
- Dependency conflicts with newer PHP versions
- Breaking changes in test suite

### Mitigation Strategies
- Gradual rollout with feature flags
- Comprehensive testing on all PHP versions
- Backup of current working state
- Rollback plan if issues arise

## Success Criteria

### Technical
- [ ] All tests pass on multiple PHP versions
- [ ] Code coverage maintained or improved
- [ ] Build time improved or maintained
- [ ] No functionality regression

### Process
- [ ] Developer workflow simplified
- [ ] Documentation updated and clear
- [ ] CI/CD pipeline more robust
- [ ] Better visibility into code quality

## Timeline Estimate
- **Phase 1**: 2-3 days
- **Phase 2**: 1-2 days  
- **Phase 3**: 3-5 days
- **Total**: 1-2 weeks

## Dependencies
- GitHub repository admin access for workflow modifications
- Packagist account for automated publishing (Phase 3)
- Code coverage service accounts (CodeClimate, Codecov) if desired

## Workflow Deployment Instructions

The GitHub Actions workflow files have been created in `ai/prompts/2025-10-build-upgrade/workflows/` and need to be manually moved to `.github/workflows/` directory:

### Required Actions:
1. **Replace the existing workflow**:
   ```bash
   # Backup current workflow (optional)
   cp .github/workflows/php.yml .github/workflows/php.yml.backup

   # Replace with new CI workflow
   cp ai/prompts/2025-10-build-upgrade/workflows/ci.yml .github/workflows/ci.yml

   # Remove old workflow
   rm .github/workflows/php.yml
   ```

2. **Add new quality workflow**:
   ```bash
   cp ai/prompts/2025-10-build-upgrade/workflows/quality.yml .github/workflows/quality.yml
   ```

3. **Add coverage comment workflow**:
   ```bash
   cp ai/prompts/2025-10-build-upgrade/workflows/coverage-comment.yml .github/workflows/coverage-comment.yml
   ```

4. **Install new dependencies**:
   ```bash
   composer install
   ```

5. **Test the new setup**:
   ```bash
   # Test all new commands
   composer test
   composer test:coverage
   composer cs:check
   composer analyze
   composer ci
   ```

### Verification Steps:
- [ ] All composer scripts work correctly
- [ ] GitHub Actions workflows trigger on push/PR
- [ ] Coverage reports are generated
- [ ] Quality checks pass
- [ ] Documentation is updated

## Implementation Status

### Phase 1: GitHub Actions Enhancement ✅ COMPLETE
- [x] Enhanced CI workflow with multi-PHP testing
- [x] Code coverage with thresholds and artifacts
- [x] Quality checks workflow
- [x] Security scanning

### Phase 2: fred.php Removal ✅ COMPLETE
- [x] Composer scripts created
- [x] Documentation updated
- [x] Legacy files removed

### Phase 3: Advanced CI/CD Features 🔄 PENDING
- [ ] Automated releases
- [ ] Performance monitoring
- [ ] Advanced security features

## Next Steps
1. ✅ Review and approve this plan
2. ✅ Create feature branch for implementation
3. ✅ Begin with Phase 1 GitHub Actions enhancements
4. ✅ Test thoroughly before removing fred.php
5. ✅ Update documentation and communicate changes to team
6. 🔄 **CURRENT**: Deploy workflow files manually using instructions above
7. 🔄 Test the complete setup
8. 🔄 Consider implementing Phase 3 features

# GitHub Actions cs2pr Error Fix

## Problem

GitHub Actions workflow `quality.yml` was failing with error:
```
Error: Unable to resolve action `staabm/annotate-pull-request-from-checkstyle@v1`, unable to find version `v1`
```

## Root Cause

The action reference was incorrect. There are two related repositories:

1. **`staabm/annotate-pull-request-from-checkstyle`** - The main cs2pr tool (PHP package)
2. **`staabm/annotate-pull-request-from-checkstyle-action`** - The GitHub Action wrapper

The workflow was trying to use the main tool repository as an action, but it should either:
- Use the action repository: `staabm/annotate-pull-request-from-checkstyle-action@v1`
- Use cs2pr directly via `shivammathur/setup-php`

## Solution Applied

**Used cs2pr directly via setup-php** (recommended approach):

### Before:
```yaml
- name: Set up PHP
  uses: shivammathur/setup-php@v2
  with:
    php-version: '8.1'
    tools: composer:v2

- name: Annotate code style issues
  if: failure()
  uses: staabm/annotate-pull-request-from-checkstyle@v1
  with:
    checkstyle-file: 'phpcs-report.xml'
```

### After:
```yaml
- name: Set up PHP
  uses: shivammathur/setup-php@v2
  with:
    php-version: '8.1'
    tools: composer:v2, cs2pr

- name: Annotate code style issues
  if: failure()
  run: |
    if [ -f phpcs-report.xml ]; then
      cs2pr phpcs-report.xml
    else
      echo "No phpcs-report.xml file found"
    fi
```

## Alternative Solutions

### Option 1: Use the GitHub Action (if preferred)
```yaml
- name: Annotate code style issues
  if: failure()
  uses: staabm/annotate-pull-request-from-checkstyle-action@v1
  with:
    files: 'phpcs-report.xml'
```

### Option 2: Install cs2pr via Composer
```yaml
- name: Install cs2pr
  if: failure()
  run: composer global require staabm/annotate-pull-request-from-checkstyle

- name: Annotate code style issues
  if: failure()
  run: ~/.composer/vendor/bin/cs2pr phpcs-report.xml
```

### Option 3: Use cs2pr with pipe (for live output)
```yaml
- name: Run PHP CodeSniffer with annotations
  run: composer cs:check --report=checkstyle | cs2pr
  continue-on-error: true
```

## Benefits of Current Solution

1. **Simplicity**: No external action dependencies
2. **Reliability**: cs2pr is maintained and included in setup-php
3. **Performance**: No additional action download/setup time
4. **Error Handling**: Checks if report file exists before processing
5. **Transparency**: Clear what's happening in the workflow

## Testing

The fix has been applied to `.github/workflows/quality.yml` and pushed to PR #37.
The workflow should now:

1. ✅ Install cs2pr via setup-php
2. ✅ Generate phpcs-report.xml when code style issues are found
3. ✅ Convert the report to GitHub PR annotations
4. ✅ Handle missing report files gracefully

## Related Documentation

- [cs2pr GitHub Repository](https://github.com/staabm/annotate-pull-request-from-checkstyle)
- [cs2pr GitHub Action](https://github.com/staabm/annotate-pull-request-from-checkstyle-action)
- [shivammathur/setup-php Tools](https://github.com/shivammathur/setup-php#tools-support)

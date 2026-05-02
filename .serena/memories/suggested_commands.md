# Suggested commands

## Composer scripts (preferred)
```bash
composer install            # install deps

composer test               # full PHPUnit run with coverage settings from phpunit.xml
composer test:quick         # PHPUnit without coverage (fast feedback loop)
composer test:coverage      # HTML coverage to build/coverage/
composer test:ci            # clover coverage for CI

composer cs:check           # phpcs (PSR-12 + custom rules in phpcs.xml)
composer cs:fix             # phpcbf (auto-fix style)
composer analyze            # phpstan analyse src/ test/ --level=5
composer quality            # cs:check + analyze
composer ci                 # test:ci + quality
```

## Direct tools (when composer wrappers don't fit)
```bash
vendor/bin/phpunit test/anorm/DataMapper_Crud_Test.php
vendor/bin/phpunit --filter testWriteThenRead
vendor/bin/phpcs src/DataMapper.php
vendor/bin/phpstan analyse src/DataMapper.php --level=5
```

## Scaffolding
```bash
php bin/anorm.php            # CLI entry; generates models from existing tables
```

## Docker / devcontainer
- See `Dockerfile.test`, `README-DEVCONTAINER.md`, `.env.devcontainer` (gitignored).

## Linux system commands (just confirming, this box is Linux)
- File ops: `ls`, `find`, `grep -R`, `rg` (if available)
- Git: standard. Project is on `feat/change` branch. Master branch is `master`.

# CLAUDE.md

Guidance for Claude Code (and other AI agents) working in this repository.

## What this repo is

`saygoweb/anorm` — a small, pragmatic PHP ORM for legacy/MySQL databases. Maps
camelCase model properties to snake_case columns, provides a Model base class for
IDE autocomplete, and stays out of the way for complex queries.

## Layout (the parts you'll touch most)

- `src/`
  - `Model.php` — base class user models extend. Holds `$_mapper`, `$_pdo`,
    `$_relationshipManager`, `$_loadedFields`. Underscore-prefixed properties
    are infrastructure and are skipped by the column map.
  - `DataMapper.php` — owns `map`, `transformers`, `table`, `useReplace`. Hot
    methods: `write()` (INSERT/UPDATE/REPLACE), `readArray()` (single hydration
    chokepoint — every read path eventually goes through it), `read()`,
    `readRow()`, `delete()`, `find()`.
  - `QueryBuilder.php` — fluent query builder. Calls back into `DataMapper::readArray`.
  - `MangoQuery.php` / `MangoQueryParser.php` — Mongo-style query DSL.
  - `Relationship/` — `hasMany`, `belongsTo`, `hasManyThrough`, batch loaders
    (N+1 mitigation).
  - `Transform/` — value-object transformers; contract is `TransformInterface`.
  - `SqlCondition.php`, `TableMaker.php`.
- `test/anorm/` — PHPUnit tests + fixture models. Test classes use underscored
  names (e.g. `DataMapper_Crud_Test`); phpcs explicitly allows this in `test/`.
- `tools/`, `bin/anorm.php` — model-from-table scaffolding CLI.
- `docs/` — Jekyll site published to `saygoweb.github.io/anorm`.
- `ai/` — **gitignored** scratch space for AI-collaboration plans/specs.

## Conventions you must respect

- **Property prefix `_`** marks an infrastructure property on `Model` — it is
  skipped by `DataMapper::write` and `readArray`. Use this for any new internal
  state on models that should not become a column.
- **Naming:** `camelCase` properties ↔ `snake_case` columns (auto-mapped).
- **Standard infra columns** common in Anorm consumers (not enforced by
  Anorm itself): `id`, `dtc` (created), `dtu` (updated), `uc`, `uu`.
- **Single hydration chokepoint:** every read path funnels through
  `DataMapper::readArray` — that's the place to attach cross-cutting concerns
  like snapshotting.
- **PHP version compatibility:** `composer.json` does not pin `php`. Existing
  code is PHP 7.4-style (nullable typed args, return types). **Avoid PHP 8-only
  syntax in public API** unless explicitly approved (no constructor property
  promotion, no enums, no `readonly`, no `match`).
- **Errors:** prefer `error_log` to a `LoggerInterface` dependency — matches
  precedent in `Model::createForeignKey`.
- **Style:** PSR-12 + repo-specific `phpcs.xml` (line length 150 src / 200 test;
  short array syntax required).

## Commands

```bash
composer install

# tests
composer test:quick    # PHPUnit, no coverage — fast feedback
composer test          # full PHPUnit
composer test:coverage # HTML coverage to build/coverage/

# quality
composer cs:check      # phpcs (PSR-12 + custom)
composer cs:fix        # phpcbf auto-fix
composer analyze       # phpstan level 5
composer quality       # cs:check + analyze
composer ci            # test:ci + quality
```

Direct tools when needed:

```bash
vendor/bin/phpunit --filter testWriteThenRead
vendor/bin/phpcs src/DataMapper.php
vendor/bin/phpstan analyse src/DataMapper.php --level=5
```

## Done-criteria for any change

1. `composer test:quick` passes.
2. `composer cs:check` clean (`composer cs:fix` if needed).
3. `composer analyze` clean at level 5.
4. `composer test` passes before declaring complete.
5. New public API → tests under `test/anorm/`, plus a docs note in
   `docs/_docs/` if user-facing.

## Don't commit

- `.env*` (gitignored).
- `build/` artefacts.
- Anything under `ai/` (gitignored — planning/scratch only).

## Tips for navigating

- For symbol-level browsing, prefer Serena's `get_symbols_overview` /
  `find_symbol` over reading whole files — `DataMapper.php` and `Model.php`
  are large.
- Existing Serena memories cover project overview, suggested commands, code
  style, and the in-flight 2026-05 change-detection request.

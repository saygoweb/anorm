# Anorm — project overview

Anorm ("Another ORM for PHP") is a small PHP ORM library targeting legacy/pragmatic
schemas. Package: `saygoweb/anorm` (Composer). MIT license.

## Purpose / philosophy
- Works well with legacy databases.
- Provides a `Model` base class so IDEs can autocomplete properties.
- Can create/modify the underlying schema to match the model (`TableMaker`).
- Maps `camelCase` model properties to `under_score` SQL fields automatically.
- Stays out of the way of complex queries (raw PDO is fine).

## Tech stack
- **Language:** PHP (uses PDO directly; no Doctrine/Eloquent).
- **PHP version:** composer.json does not pin a `php` version. Tests and source use modern features (return types, nullable types, `?array`). Treat as PHP 7.4+ in practice; do not use PHP 8-only syntax in public API unless asked.
- **Test runner:** PHPUnit ^9.6.
- **Static analysis:** PHPStan ^1.10 at level 5.
- **Code style:** PSR-12 via `squizlabs/php_codesniffer` ^3.7. Custom `phpcs.xml` adds rules (line length 150 src / 200 test, short array syntax required, etc.).
- **DB:** MySQL/MariaDB (uses backticks, `REPLACE INTO`, `lastInsertId`).
- Test environment expects `.env` or `.env.devcontainer` for DB creds (see `test/anorm/TestEnvironment.php`).

## High-level layout
- `src/` — library code:
  - `Anorm.php` — façade / static PDO holder.
  - `Model.php` — base class extended by user models. Holds `$_mapper`, `$_pdo`, `$_relationshipManager`, `$_loadedFields`. Underscore-prefixed properties are excluded from the column map.
  - `DataMapper.php` — owns `map`, `transformers`, `table`, `useReplace`. Methods: `write()` (INSERT/UPDATE/REPLACE branch), `readArray()` (single hydration chokepoint), `read()`, `readRow()`, `delete()`, `find()`.
  - `QueryBuilder.php` — fluent query builder; calls back into `DataMapper::readArray` via `readRow`.
  - `MangoQuery.php` / `MangoQueryParser.php` — Mongo-style query DSL.
  - `Relationship/` — `hasMany`, `belongsTo`, `hasManyThrough`; batch loading orchestrator solves N+1.
  - `Transform/` — value-object transformers (`SqlDateTimeTransform`, `JsonArrayTransform`, `FunctionTransform`); contract is `TransformInterface::txDatabaseToModel` / `txModelToDatabase`.
  - `SqlCondition.php`, `TableMaker.php`.
- `test/anorm/` — PHPUnit tests + fixture models (`UserModel`, `CompanyModel`, `PostModel`, etc.). Underscore-named test classes (`DataMapper_Crud_Test`) are the convention; phpcs rule explicitly allows this in tests.
- `tools/` and `bin/anorm.php` — code-gen CLI for scaffolding models from existing tables.
- `docs/` — Jekyll site published to GitHub Pages (`saygoweb.github.io/anorm`).
- `ai/` — local-only (in `.gitignore`); used for plans / AI-collaboration scratch space.

## Conventions worth knowing
- Property naming: model properties are `camelCase`; database columns are `snake_case`. `DataMapper` auto-maps via `splitUpper` + `propertyName`.
- Properties starting with `_` (underscore) are infrastructure; `DataMapper::write` and `readArray` skip them. The library uses this for `$_mapper`, `$_pdo`, `$_relationshipManager`, `$_loadedFields`. **Anything new that should not be a column belongs to this family.**
- `DataMapper` has two modes: `MODE_STATIC` (column map declared in model constructor) and `MODE_DYNAMIC` (auto-discovered from DB).
- Standard infra columns commonly seen on consumer projects: `id`, `dtc` (created), `dtu` (updated), `uc` (user created), `uu` (user updated). They are not enforced by Anorm itself but are common Anorm-consumer convention.
- Partial loading: `Model::setLoadedFields(?array)` and `getLoadedFields()` track which columns were hydrated, so that callers can avoid touching never-loaded values.
- Single hydration chokepoint: every read path (`Model::read`, `QueryBuilder::one`, `QueryBuilder::some`, batch loaders) ultimately calls `DataMapper::readArray` — so any cross-cutting concern (e.g. snapshotting) attaches there.

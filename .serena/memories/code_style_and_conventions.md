# Code style & conventions

## Lint baseline
- **PSR-12** via phpcs (`phpcs.xml`).
- Source line length: 150 chars (error), absolute 200.
- Test line length: 200 chars (warning), absolute 250.
- Short array syntax required (`[]`, never `array()`).
- No inline control structures, no multiple statements per line.
- Constants must declare visibility.

## Static analysis
- PHPStan level 5 over `src/` and `test/`.
- Known ignore: `Moment\Moment` not found (external test-only dep).

## Naming
- Classes: `PascalCase` in `src/` (`DataMapper`, `QueryBuilder`).
- Methods/properties on models: `camelCase`. **DB columns are `snake_case`** — auto-mapped by `DataMapper::propertyName`.
- Underscore-prefixed properties (`$_mapper`, `$_loadedFields`) are infrastructure and skipped by the column map. **Use this convention for any new internal-state property on `Model`.**
- Test classes use underscored names like `DataMapper_Crud_Test` (allowed by phpcs override for `test/`).

## Type hints / docblocks
- Source uses gradual typing — many older methods lack return types; newer ones (`setLoadedFields(?array $fields): void`) use them.
- Docblocks are short; `@param`/`@return` only when the type is non-trivial.
- Don't over-document. Prefer type-hinted signatures.

## Visibility
- `Model::$_mapper`, `Model::$_relationshipManager` are intentionally `public` so `DataMapper` and `RelationshipManager` can interoperate.
- `Model::$_pdo` is `protected`, exposed via `getPdo()`.
- Treat new infrastructure properties on `Model` as `public` (with `_` prefix) when they need to be reachable from `DataMapper` or listeners — matches existing pattern.

## PHP version compatibility
- Library does not pin a minimum PHP version in `composer.json`. Existing code uses PHP 7.4-style syntax (nullable typed parameters, return types). **Avoid PHP 8-only constructs in public API** (no constructor property promotion, no enums, no readonly, no `match`) unless explicitly approved.
- `\Throwable` is fine (PHP 7+).
- Static methods using `?Foo $x` typed args are fine.

## Error handling
- The codebase prefers `error_log` over a `LoggerInterface` dependency (see `Model::createForeignKey` line ~455).
- Throwing `\Exception` directly is the existing pattern for invariant violations.

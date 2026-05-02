# Done-criteria checklist

When finishing a coding task on this repo, run (in roughly this order):

1. `composer test:quick` — fast PHPUnit pass.
2. `composer cs:check` — phpcs clean. Auto-fix with `composer cs:fix` if needed.
3. `composer analyze` — phpstan level 5 clean.
4. `composer test` — full PHPUnit (the CI variant) before declaring complete.

Optional / contextual:
- If touching public API, add tests under `test/anorm/` (mirror existing layout — fixture models live alongside test classes).
- If adding new files, ensure PSR-4 autoload covers them (namespaces are `Anorm\\` for `src/`, `Anorm\\Test\\` for `test/anorm/`, `Anorm\\Tools\\` for `tools/src/`).
- Update `docs/_docs/` if user-facing behaviour or API changed.
- Update `README.md` if a new top-level feature warrants a callout.

Do **not** commit:
- `.env*` files (gitignored).
- `build/` artefacts.
- Anything under `ai/` (gitignored — used as scratch / planning workspace).

Branch hygiene:
- `master` is the main branch. Feature branches like `feat/change` are typical.
- Commits should be self-contained; tests should pass at every commit.

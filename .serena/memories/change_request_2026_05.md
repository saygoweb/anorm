# 2026-05 Change-detection feature request

## Where it lives
- Spec docs (gitignored, in `ai/2026-05-change/`):
  - `1-change-plan.md` — downstream consumer's full plan (emdc-events #222). Builds change-detection + email subscription system on top of Anorm.
  - `2-anorm-spec.md` — proposal to Anorm for the upstream hooks.
- Working branch: `feat/change`.

## Anorm-side ask, in one paragraph
Expose lifecycle hooks so a single registered listener is told *what changed* on every successful `DataMapper::write()`. Three additions:
1. `Anorm\Lifecycle\ChangeListenerInterface::onWrite(Model, array $diff, bool $isInsert)`.
2. `DataMapper::setChangeListener(?ChangeListenerInterface)` (proposed static, possibly instance — open question).
3. `Model::$_snapshot` populated at the end of `DataMapper::readArray()` and refreshed at end of successful `write()`. `_`-prefix means it's auto-excluded from the column map.
Plus a public `DataMapper::diff(array $snapshot, Model $current): array` returning `[prop => ['from'=>...,'to'=>...]]`.

## Constraints (consumer's words, worth honouring)
- Zero behaviour change for existing users; existing tests pass unmodified.
- Zero allocation when feature unused (one null check on the read path is fine).
- Single hydration chokepoint = `DataMapper::readArray`.
- No domain knowledge in Anorm — listener decides what to do.
- PHP-version-neutral public API.

## Open questions (verbatim §11 of spec)
1. **Static vs instance listener.** Spec defaults to static; offers instance fallback.
2. **Excluded-properties list** for diff. Hard-coded `[id, dtc, dtu, uc, uu]` or configurable?
3. **Partial-load semantics.** Recommendation A (omit unloaded from diff) or B (include with `from=null`)?
4. **Object equality.** Sniff for `equals`/`isSame`, fall back to `serialize` — or stricter contract?
5. **`onDelete` in v1**, or defer?
6. **Naming:** `_snapshot` vs `_dbSnapshot` vs `_originalValues`.

## Internal notes I should keep in mind when designing
- Anorm itself does not assume `dtc`/`dtu`/`uc`/`uu` exist — those are downstream conventions. Hard-coding the exclude list assumes a convention Anorm doesn't itself enforce; lean toward making it configurable per `DataMapper`.
- `clone` on snapshot capture is a sensible default for value objects but breaks for resources / un-cloneable objects. Per-transformer opt-out (§6.4 of spec) is the right escape hatch — but probably v2.
- `_`-prefix exclusion lives in `DataMapper::write` (line ~120, ~153 in current file) and `DataMapper::readArray` (line ~252). `$_snapshot` will Just Work with that.
- The static-listener concern in tests: `tearDown()` must reset, otherwise tests bleed.

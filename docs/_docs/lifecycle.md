---
title: Lifecycle hooks
category: Advanced
order: 1
---

# Lifecycle hooks

Anorm exposes a single lifecycle hook, `ChangeListenerInterface`, for code that
needs to know what changed on every successful `DataMapper::write()`.

## When to use it

- Audit logs.
- Change notifications (email, Slack, webhooks).
- Cache invalidation tied to specific field changes.
- Anything that needs `(model, diff, isInsert)` and would otherwise be
  duplicated at every mutation call site.

## Registering a listener

```php
use Anorm\DataMapper;
use Anorm\Lifecycle\ChangeListenerInterface;
use Anorm\Model;

class AuditListener implements ChangeListenerInterface
{
    public function onWrite(Model $model, array $diff, bool $isInsert): void
    {
        if ($isInsert) {
            error_log('Inserted ' . get_class($model) . ' #' . $model->id);
            return;
        }
        foreach ($diff as $property => $change) {
            error_log(sprintf(
                '%s#%d.%s: %s -> %s',
                get_class($model), $model->id, $property,
                var_export($change['from'], true),
                var_export($change['to'], true)
            ));
        }
    }
}

DataMapper::setChangeListener(new AuditListener());
```

`setChangeListener` is static. Pass `null` to remove the listener (e.g. in test
`tearDown`).

## What the listener receives

- **`$model`** — the model that was just persisted, with its primary key
  populated even on INSERT.
- **`$diff`** — `['property' => ['from' => mixed, 'to' => mixed]]`. Empty when
  nothing changed; always empty when `$isInsert` is true.
- **`$isInsert`** — true when the model had no prior snapshot, indicating an
  INSERT-equivalent write.

## Excluding fields from the diff

By default, `diff` reports every mapped property except:

- The primary key (Anorm knows this via `$modelPrimaryKey`).
- Properties prefixed with `_` (the existing convention for non-column
  properties).
- Properties not in `Model::getLoadedFields()` when partial loading is in
  effect.

To exclude additional properties (timestamps, audit columns, etc.), set
`DataMapper::$infrastructureProperties`:

```php
$mapper = DataMapper::createByClass($pdo, $model);
$mapper->infrastructureProperties = ['dtc', 'dtu', 'uc', 'uu'];
```

## Snapshot lifecycle

`Model::$_lastSnapshot` is the per-model record of "values as last seen in the
database." It is populated at the end of `DataMapper::readArray()` and
refreshed at the end of every successful `write()`. It is `null` until the
first read while a listener is registered.

**Important:** snapshot capture is gated on `setChangeListener` being non-null.
Register the listener at boot, before any reads of models that will later be
written. A model read before the listener was registered will be treated as an
INSERT on its next write (`isInsert=true, diff=[]`) — which is harmless but
incorrect for change tracking.

## Re-entrancy

A listener must not call `DataMapper::write()` synchronously, because the
recursive write would itself fire the listener. Doing so raises
`Anorm\Lifecycle\ReentrantWriteException`, surfaced to the caller of the
outer `write()`. The snapshot is still refreshed before the exception
propagates, so the caller's view of `$_lastSnapshot` reflects the committed
state.

If the listener needs to persist follow-up records, queue them in memory and
flush at the end of the request (e.g. via a middleware after-handler).

## Listener exceptions

Anorm wraps the listener call in `try/catch`. Any exception other than
`ReentrantWriteException` is logged via `error_log` and swallowed; the write
itself succeeds. Listener faults must never break writes.

If you want strict behaviour, your listener can catch and re-throw a wrapper
type — but most consumers prefer fire-and-forget.

## Object equality

For object-valued properties, `diff` calls `equals($other)` if defined,
otherwise `isSame($other)`, otherwise PHP's loose `==` (which compares all
properties recursively for property-bag value objects). It does **not** fall
back to `serialize`. Implement `equals()` on value objects whose semantic
equality differs from property equality.

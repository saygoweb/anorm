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

A listener may call `DataMapper::write()` — for example, to persist a
follow-up record on a different table in response to a change. Nested writes
commit their SQL and refresh their own `$_lastSnapshot`, but do **not**
re-invoke the listener. Anorm assumes a single in-flight listener and
suppresses recursive notifications for any depth.

## Delete events

`ChangeListenerInterface` covers writes only. To hear about deletions,
implement `DeleteListenerInterface` and register it with `setDeleteListener`:

```php
use Anorm\DataMapper;
use Anorm\Lifecycle\DeleteListenerInterface;
use Anorm\Model;

class DeleteAuditListener implements DeleteListenerInterface
{
    public function onDelete(string $table, $id, ?Model $model): void
    {
        error_log("Deleted $table #$id");
    }
}

DataMapper::setDeleteListener(new DeleteAuditListener());
```

The two hooks are separate interfaces on separate slots so that neither
imposes on the other: an existing write-only listener keeps compiling
untouched, and a delete-only listener does not have to supply a no-op
`onWrite`. One class may implement both, in which case register it twice:

```php
class AuditListener implements ChangeListenerInterface, DeleteListenerInterface
{
    // onWrite() and onDelete()
}

$listener = new AuditListener();
DataMapper::setChangeListener($listener);
DataMapper::setDeleteListener($listener);
```

Pass `null` to either setter to remove that listener (e.g. in test `tearDown`).

### What `onDelete` receives

- **`$table`** — the table the row was deleted from. Always present.
- **`$id`** — the primary key value that was deleted. Always present.
- **`$model`** — the model, when the delete came through `Model::delete()` or
  `Model::deleteOrThrow()`. It is `null` for `$mapper->delete($id)`, because
  the mapper knows the table and the map but not the model class.

If your listener needs the deleted row's values, delete through the model:

```php
$model->readOrThrow($id);
$model->delete();   // onDelete receives the populated model
```

### When it fires

`onDelete` fires only when the DELETE removed at least one row. A delete that
matched nothing is a no-op and produces no event.

Re-entrancy matches `onWrite`, and the guard is shared between the two hooks:
a listener that deletes or writes from inside `onDelete` is not re-invoked.
Exceptions are handled the same way too — a listener that throws is logged via
`error_log` and swallowed, and the delete still reports success.

### Snapshots

After a successful `delete()` that was given a model, that model's
`$_lastSnapshot` is set to `null`, so a subsequent `write()` on the same object
is correctly reported as an INSERT rather than diffed against a row that no
longer exists. The listener runs before the snapshot is cleared, so
`$model->_lastSnapshot` still holds the pre-delete values inside `onDelete`.

## Listener exceptions

Anorm wraps the listener call in `try/catch`. Any exception thrown by the
listener is logged via `error_log` and swallowed; the write itself succeeds.
Listener faults must never break writes.

If you want strict behaviour, your listener can catch and re-throw a wrapper
type — but most consumers prefer fire-and-forget.

## Object equality

For object-valued properties, `diff` calls `equals($other)` if defined,
otherwise `isSame($other)`, otherwise PHP's loose `==` (which compares all
properties recursively for property-bag value objects). It does **not** fall
back to `serialize`. Implement `equals()` on value objects whose semantic
equality differs from property equality.

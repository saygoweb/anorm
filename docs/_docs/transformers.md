---
title: Transformers
category: Advanced
order: 4
---

# Transformers

A transformer converts a value between the shape a model wants and the shape a column
holds. It is registered per column on the mapper:

```php
$mapper->transformers = [
    'occurred_at'    => new SqlDateTimeTransform(),
    'email_verified' => new BooleanTransform(),
    'options'        => new JsonArrayTransform(),
];
```

The key is the **column** name, not the property name, because a transformer is about
storage. Every read goes through `DataMapper::readArray()` and every write through
`DataMapper::write()`, so a registered transformer is applied on both paths and nowhere
else has to know.

## The contract

```php
interface TransformInterface
{
    public function txDatabaseToModel($value);
    public function txModelToDatabase($value);
}
```

Both directions are given whatever the other side actually holds, including `null`. A
transformer that does not handle `null` will be handed it the first time a column is
empty, so handle it deliberately: a nullable column has a third state, and collapsing
it to `false`, `0` or an epoch date loses the one that means "not set".

`txModelToDatabase()` returning `null` writes SQL `NULL` rather than a quoted empty
string.

## Saying what column the format needs

A transformer has already decided how the value is stored, which makes it the one part
of a mapper that *knows* the column type rather than guessing at it. It can say so:

```php
interface ColumnTypeHintInterface
{
    public function sqlColumnType();   // e.g. 'DATETIME NULL', or null
}
```

Implement it alongside `TransformInterface` and the answer is used for two things: the
column [dynamic mode](schema-modes.html) creates, and the column
[`anorm schema:diff`](schema-diff.html) expects to find. Both ask the same question in
the same place, so they cannot drift apart.

This is **the highest-confidence source of a column type Anorm has, short of pinning
the column outright** — above a declared property type, and far above a sampled value.
A sample is whatever happened to be written first, and the value that reaches a date
column first is very often `null`, which is how a timestamp column ends up
`VARCHAR(128)` and stays that way. A transformer's answer does not depend on timing.

It is a separate interface so that implementing it stays optional. Not every
transformer knows its column type — `FunctionTransform` wraps two arbitrary closures
and genuinely cannot — and a transformer written before this existed keeps working
untouched. Returning `null` from `sqlColumnType()` means the same thing as not
implementing it: no opinion, fall through to the declared type and then the sample.

## Writing one

Nothing about a transformer has to live in Anorm. A class the core knows nothing about
is the normal case, and declaring its column type works exactly the same:

```php
namespace App\Transform;

use Anorm\Schema\ColumnTypeHintInterface;
use Anorm\TransformInterface;
use Moment\Moment;

class MomentTransform implements TransformInterface, ColumnTypeHintInterface
{
    public function txDatabaseToModel($value)
    {
        return $value === null ? null : new Moment($value);
    }

    public function txModelToDatabase($value)
    {
        return $value === null ? null : $value->format('Y-m-d H:i:s');
    }

    public function sqlColumnType()
    {
        return 'DATETIME NULL';
    }
}
```

Register it and the column is `DATETIME` from the first write, even when the first
value written is `null`:

```php
$mapper->transformers = ['occurred_at' => new MomentTransform()];
```

```sql
CREATE TABLE `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime DEFAULT NULL,
  ...
)
```

Run `schema:diff` against a legacy table where that column is still text and it is
reported, because the transformer is what the model implies:

```
[WARNING] events.occurred_at — is VARCHAR(128), model implies DATETIME NULL — dates stored as text
```

## The ones that ship

| | Model side | Column | |
| --- | --- | --- | --- |
| `SqlDateTimeTransform` | `\DateTime` | `DATETIME NULL` | Format is constructor-configurable |
| `BooleanTransform` | `bool` | `TINYINT(1) NULL` | `NULL` stays `NULL` |
| `JsonArrayTransform` | `array` | `TEXT` | |
| `FunctionTransform` | anything | — | Two closures; states no column type |

## Where a transformer sits among the other answers

When dynamic mode creates a column, or `schema:diff` works out what a model implies,
the sources are consulted in this order:

1. `$mapper->columnDefinitions` — the column pinned outright.
2. **A transformer implementing `ColumnTypeHintInterface`.**
3. The type the model declares for the property.
4. A value sampled from the model.
5. `VARCHAR(128)`, which is what no information at all looks like.

A transformer sits above the declaration on purpose. `/** @var string */` on a property
holding an ISO datetime is true and useless; the transformer knows it writes a
formatted date. See [schema modes](schema-modes.html) for the whole order and what each
source is worth.

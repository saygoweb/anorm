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

This is the difference between **knowing** a column type and **inferring** one, and it
is a difference in kind rather than in confidence.

Reading a property's declared type or sampling its value is inference: a reading of
evidence, which may be right and cannot be certain. `/** @var string */` on a property
holding an ISO datetime is a true statement that implies the wrong column. A sample is
whatever happened to be written first, and the first value to reach a date column is
very often `null` — which is how a timestamp ends up `VARCHAR(128)` and stays that way.

A transformer is not evidence about the format. It *is* the format.
`SqlDateTimeTransform` does not think the column is a date; it writes one. So its
answer does not depend on what a property was annotated with or on which value arrived
first, and it is taken above both.

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

| | Source | |
| --- | --- | --- |
| 1 | `$mapper->columnDefinitions` | told outright |
| 2 | **a transformer implementing `ColumnTypeHintInterface`** | **known** |
| 3 | the type the model declares for the property | inferred |
| 4 | a value sampled from the model | inferred |
| 5 | `VARCHAR(128)` | nothing at all |

`ColumnIntent` records which of these an answer came from as well as the answer itself,
which is why `schema:diff` can report a difference from an informed source as drift and
stay quiet about a column that nothing has an opinion on. See
[schema modes](schema-modes.html) for what each source is worth.

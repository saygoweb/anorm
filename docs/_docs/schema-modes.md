---
title: Schema modes
category: Advanced
order: 2
---

# Schema modes

Anorm has two schema modes. `MODE_STATIC` is the default, and is what production is
expected to run. `MODE_DYNAMIC` creates and alters tables to match your models, and is
a **development aid**.

```php
$this->mapper()->mode = DataMapper::MODE_DYNAMIC; // development
$this->mapper()->mode = DataMapper::MODE_STATIC;  // the default, and production
```

## Why inference cannot be correct

In dynamic mode, a column that does not exist yet is created from whatever the model
can say about the property. Where it says nothing, that is a SQL type guessed from one
PHP value, and such a guess cannot be right in principle:

- a PHP `int` does not say whether you meant `INT` or `BIGINT`;
- a PHP `string` does not say whether you meant `VARCHAR(32)`, `VARCHAR(255)`, `TEXT`,
  `DATETIME` or `CHAR(2)`;
- a `null` says nothing at all;
- nothing in a value expresses an index, a foreign key, a collation, a default, or
  whether the column should be `NOT NULL`.

Anorm makes the most useful guess it can and gets on with it. It is a starting point
for a schema, not a schema.

There is a second, sharper consequence: **the type is decided by whichever value
reaches the column first, and then it sticks.** A column created by a lookup before the
first write is typed from whatever that lookup could sample. Two environments that run
the same code in a different order can end up with genuinely different schemas.

Declaring your property types is the way out of that one — see below.

## What the guess consults, in order

There are two tiers to this, and the difference between them is not confidence but
kind. Anorm can **infer** a column type from a property's declared type or from a value
it has seen — a reading of evidence, which may be right and cannot be certain. And it
can **know** one from a registered transformer, because a transformer has already
decided the storage format: `SqlDateTimeTransform` does not think the column is a date,
it writes one.

Knowing beats inferring, and neither is consulted where the column has simply been
pinned. In full, a column that does not exist yet takes its definition from the best
information the mapper and the model can offer:

1. **An explicit definition on the mapper** — `$mapper->columnDefinitions`, below.
2. **A transformer that knows the format it writes.** `SqlDateTimeTransform` writes a
   formatted date, so its column is `DATETIME`; `JsonArrayTransform` writes encoded
   JSON, so its column is `TEXT`. Your own transformer can say the same by
   implementing `Anorm\Schema\ColumnTypeHintInterface` — see
   [transformers](transformers.html). This is the highest-confidence answer short of
   pinning the column, because it does not depend on which value arrived first.
3. **The type the model declares for the property** — a PHP 7.4 typed property, or an
   `@var` docblock. A declaration is intent rather than an accident of which value
   arrived first, so it is preferred over any sample.
4. **A value sampled from the model**, which is where the type used to come from.
5. **`VARCHAR(128)`**, which is what no information at all looks like.

Sources 3 and 4 are the inference tier and they are not rivals. The declaration settles
the type, and the sample refines what it leaves open, because `int` does not say `INT`
or `BIGINT` and `string` does not say how wide:

```php
class HostingModel extends Model
{
    public $id;
    public ?int $clientId = null;   // INT(11), even on a read before the first write
    public int $trafficBytes = 0;   // INT(11), or BIGINT(20) once a large value is written
    public ?string $note = null;    // VARCHAR(128), widening to TEXT on a long value

    /** @var ?bool */
    public $isActive;               // TINYINT(1) — a docblock says the same thing
}
```

A column whose type comes from a declaration is the same in every environment,
whichever ran a lookup first. A property that declares nothing behaves exactly as it
did: sampled, then `VARCHAR(128)`.

Replacing `Anorm::$columnFn` still replaces the guess itself, and still has the last
word over both the declaration and the sample. It is passed the declared type as a
third argument, which a function written against the old two-argument signature
ignores.

### A declared property is a column before it holds a value

`public int $hitCount;` has no value until something assigns one, so PHP does not
report it among the object's variables. Anorm reads what the class declares as well,
so a property like this is mapped to a column and is written as `NULL` until it is set.

One combination cannot be made to work: a property declared non-nullable against a
column that holds `NULL`. Reading it is a PHP `TypeError`, which Anorm rethrows naming
the table, the column and the property. Declare the property nullable
(`public ?int $hitCount;`), give it a default (`= 0`), or make the column `NOT NULL`.

## The intended lifecycle

1. **Develop** with `MODE_DYNAMIC`, so the schema appears as the models grow.
2. **Dump** the database once the models have settled.
3. **Run `anorm schema:diff`** to see where the database and the models disagree —
   see [Schema diff]({{ site.baseurl }}/schema-diff/).
4. **Correct the dump by hand** — see the checklist below.
5. **Commit** it as a versioned schema file.
6. **Switch the models to `MODE_STATIC`** and deploy against the committed schema.

Step 4 is not optional. The dump inherits whatever dynamic mode inferred, so a column
that was guessed wrongly becomes the authoritative schema unless somebody notices.
Step 3 is how they notice.

## What to check in the correct-by-hand step

`anorm schema:diff` reports the first three of these for you, and the foreign key
constraints. Read `SHOW CREATE TABLE` for each table and look for:

- **Integer width.** Anything counting bytes, or any identifier from an external
  system, probably wants `BIGINT(20)` rather than `INT(11)`, which stops at
  2,147,483,647.
- **Foreign key columns and their constraints.** A foreign key typed `VARCHAR(128)`
  cannot take a constraint against an `INT` primary key. Check that every relationship
  your models declare has a constraint, and that its column type matches the key it
  references.
- **Text columns.** The guess tops out at `VARCHAR(256)` before `TEXT`. Anything
  holding prose, an error message or user-supplied content probably wants `TEXT`.
- **Date columns.** Check that a column typed `DATETIME` really only ever holds dates,
  and that one holding dates is not a `VARCHAR`.
- **Anything whose first written value was `0`, `''` or `false`.** These are the normal
  initial state of counters, flags and optional foreign keys, and they carry less type
  information than a typical value. Declaring the property's type settles these
  without the dump having to.
- **Anything typed `VARCHAR(128)`.** That is the shape of no information at all: a
  property that declares no type and had nothing useful to sample.
- **Indexes.** Dynamic mode adds none except those implied by a foreign key.
- **`NOT NULL`, defaults, collations and charsets.** Inference never produces these.

## Booleans

SQL has no boolean. MySQL spells one `TINYINT(1)` holding 0 or 1, and PDO hands it back
as the string `'0'` or `'1'`. Two ways to say what you mean, differing in how much you
have to say.

### `BooleanTransform`, where the mapper states the format

A transformer is how a mapper says what a column holds, the same as for a date's format
or an array's encoding:

```php
$mapper->transformers = ['email_verified' => new BooleanTransform()];
```

That settles all three questions at once. The column is `TINYINT(1)`, because a
transformer implementing `ColumnTypeHintInterface` outranks even a declared type and
does not care what value was sampled first. `true` is written as `1`. And `'0'` comes
back as `false`, so `$model->emailVerified === false` holds — which is the part nothing
else gives you.

`NULL` stays `NULL` in both directions: a nullable flag has three states, and the third
one means "not answered".

### A declared `?bool`, where PHP already knows

```php
public ?bool $emailVerified = null;
```

A typed property is enough on its own. Dynamic mode types the column `TINYINT(1)` from
the declaration, PHP coerces the column's `'0'` back to `false` on assignment to a typed
property, and Anorm writes a PHP bool as `1` or `0`.

The write half of that is not free, and is worth knowing about. `PDO::quote()` takes a
string, and PHP renders `false` as `''` on the way in. Anorm converts a bool before it
reaches `quote()`, because otherwise it would type a column `TINYINT` from the
declaration and then be unable to write to it — failing with `Incorrect integer value:
''`, which names neither the boolean nor the column.

### Which to use

Declaring `?bool` is lighter, and enough wherever you can declare it. Reach for
`BooleanTransform` when you cannot or would rather not: a docblock-typed property, where
PHP does no coercion and the property comes back a string; or a schema where the storage
format should be stated on the mapper rather than inferred from the model.

## Pinning a column explicitly

Declaring the property's type is the lighter answer, and covers a `null` property on a
read path. Pinning is for what a PHP type cannot express: a width, a magnitude that
only shows up in production, `NOT NULL`, a charset. Set the definition on the mapper:

```php
$mapper->columnDefinitions = [
    'traffic_bytes' => 'BIGINT(20) NULL',
    'reseller_id'   => 'INT(11) NULL',
];
```

These are consulted before anything else, and are per mapper, so pinning a column name
in one table does not pin it in every table that shares the name.

For a process-wide default across every table, `Anorm::$columnFn` replaces the guessing
function itself:

```php
Anorm::$columnFn = '\My\App\Schema::columnDefinition';
```

It must be installed before the first write, and it only runs when a column is
created — none of these mechanisms corrects a column that already exists. That is what
the dump-and-correct step is for.

## Foreign keys name properties, and constraints are written in columns

A relationship is declared in the terms a model author has in hand — a property:

```php
class HostingModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        // ...
        $this->belongsTo(ClientModel::class, 'clientId', 'id', 'client');
    }

    public $id;
    public ?int $clientId = null;   // the column is `client_id`
}
```

`clientId` is the property; `client_id` is the column, by the same `camelCase` to
`snake_case` rule every other property follows. Dynamic mode resolves the name through
the mapper's map before it writes any SQL, so the constraint goes on `client_id`, the
JOIN `joinRelationship('client')` builds names `client_id`, and the constraint is
called `fk_hostings_client_id`.

A model that spells the property the way the column is spelled — `public $client_id`
— is unaffected: the map maps such a property to itself.

The table a relationship points at comes from the related model's own mapper, not from
its class name. `ClientModel` reaches whatever `ClientModel` sets `$mapper->table` to,
or whatever `DataMapper::autoTable()` derived for it. Only when the related class
cannot be constructed at all does Anorm fall back to deriving a name from it.

`anorm schema:diff` resolves the same names the same way, so what it says is missing is
named the way the constraint dynamic mode would create is named.

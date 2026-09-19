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
$this->_mapper->mode = DataMapper::MODE_DYNAMIC; // development
$this->_mapper->mode = DataMapper::MODE_STATIC;  // the default, and production
```

## Why inference cannot be correct

In dynamic mode, a column that does not exist yet is created by guessing a SQL type
from one PHP value. That guess cannot be right in principle:

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

## The intended lifecycle

1. **Develop** with `MODE_DYNAMIC`, so the schema appears as the models grow.
2. **Dump** the database once the models have settled.
3. **Correct the dump by hand** — see the checklist below.
4. **Commit** it as a versioned schema file.
5. **Switch the models to `MODE_STATIC`** and deploy against the committed schema.

Step 3 is not optional. The dump inherits whatever dynamic mode inferred, so a column
that was guessed wrongly becomes the authoritative schema unless somebody notices.

## What to check in the correct-by-hand step

Read `SHOW CREATE TABLE` for each table and look for:

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
  information than a typical value.
- **Indexes.** Dynamic mode adds none except those implied by a foreign key.
- **`NOT NULL`, defaults, collations and charsets.** Inference never produces these.

## Pinning a column explicitly

Where a guess cannot reach the right answer — a `null` property on a read path, or a
magnitude that only shows up in production — set the definition on the mapper:

```php
$mapper->columnDefinitions = [
    'traffic_bytes' => 'BIGINT(20) NULL',
    'reseller_id'   => 'INT(11) NULL',
];
```

These are consulted before any value is sampled, and are per mapper, so pinning a
column name in one table does not pin it in every table that shares the name.

For a process-wide default across every table, `Anorm::$columnFn` replaces the guessing
function itself:

```php
Anorm::$columnFn = '\My\App\Schema::columnDefinition';
```

It must be installed before the first write, and it only runs when a column is
created — neither mechanism corrects a column that already exists. That is what the
dump-and-correct step is for.

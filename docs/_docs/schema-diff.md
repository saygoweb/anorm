---
title: Schema diff
category: Advanced
order: 3
---

# Schema diff

```bash
vendor/bin/anorm.php schema:diff my_database --models src/Models --namespace 'App\Models'
```

`schema:diff` compares the schema the database actually has against the one your
models imply, and prints every difference. It exists because of the step it sits in:
Anorm expects you to develop with [`MODE_DYNAMIC`]({{ site.baseurl }}/schema-modes/),
dump the database, correct the dump by hand, commit it, and switch to `MODE_STATIC`.
The dump inherits whatever inference produced, so the correcting step decides what your
authoritative schema ends up being — and without this, that step is somebody reading
`SHOW CREATE TABLE` and noticing.

It is a checklist for that person, not an oracle. A SQL type cannot be inferred
correctly from a PHP value, so a difference is frequently the *correction* rather than
the drift.

## What it prints

```
Anorm schema:diff — 3 table(s), 2 error(s), 1 warning(s), 1 informational

clients  (App\Models\ClientModel)
  ok

hostings  (App\Models\HostingModel)
  error   client_id is VARCHAR(128), model implies INT(11) NULL
  error   client_id is VARCHAR(128) and cannot be constrained against `clients`.`id`, which is INT(11)

orders  (App\Models\OrderModel)
  warning client_id has no foreign key constraint for the declared `client` relationship to `clients`.`id` (expected `fk_orders_client_id`)

1 informational finding(s) hidden; --all shows them.
```

| Severity | Means | Exit code |
| --- | --- | --- |
| `error` | the column cannot hold what the model puts in it | 1 |
| `warning` | a difference that may well be deliberate | 0 |
| `info` | context: an extra column, or one the model says nothing about | 0 |

The exit code is what makes it useful in CI: a build stops on an `error`, and a
missing foreign key or a legacy column does not stop anything.

## What it compares

"What the model implies" is the same decision `MODE_DYNAMIC` makes when it creates a
column, asked without creating anything — a pin, a transformer, the declared property
type, a sampled value, in that order. See
[Schema modes]({{ site.baseurl }}/schema-modes/).

Findings:

- **`type_mismatch`** — the live column and the implied one are different kinds of
  thing, or the live one is too narrow for what the model writes.
- **`missing_column`** / **`missing_table`** — the model maps something the database
  has not got. In `MODE_STATIC` this is the query that will fail.
- **`extra_column`** — in the database, not in the model. Ordinary in a legacy schema,
  so it is informational.
- **`no_information`** — the model declares no type for the column, holds no value and
  pins nothing, so there is nothing to compare. Declaring the property's type is what
  removes it.
- **`missing_foreign_key`** — a `belongsTo` with no constraint behind it.
- **`foreign_key_type_mismatch`** — the constraint cannot be created at all, because
  the column and the key it should reference are different types. This is the one that
  catches a foreign key typed `VARCHAR(128)` by a lookup before the first write.

## Keeping it quiet enough to read

A diff that reports every difference reports mostly noise, so it deliberately does not:

- **Widths are only compared where the model asserts one.** A declared `int` does not
  ask for `INT` over `SMALLINT`, and a declared `string` does not ask for 128
  characters — those are the guess's defaults, not intent. A pinned `BIGINT(20)`
  against a live `INT(11)` *is* reported.
- **Types are compared by family**, so `DECIMAL` and `DOUBLE` are one kind of thing.
- **A declared `string` against a `DATETIME` column is not reported at all**, because
  everything PDO returns is a string and `anorm make` documents a date column as
  `?string`. A string the model has actually *written* into a date column is a warning.
- **The primary key is left alone.** Anorm creates it with the table.
- **`$mapper->infrastructureProperties` is respected**, so `dtc`, `dtu`, `uc` and `uu`
  stay out of the report.

## Options

| Option | Default | |
| --- | --- | --- |
| `--models` | `src/Models/` | Directory holding the model classes |
| `--namespace` | `App\Models` | Only classes in this namespace are compared |
| `--host` | `db` | Database host |
| `--user`, `-u` | | Database user |
| `-p` | | Prompt for the password |
| `--all`, `-a` | | Show informational findings too |
| `--format` | `text` | `text` or `json` |

A trailing table name narrows the run to one table:

```bash
vendor/bin/anorm.php schema:diff my_database hostings --models src/Models
```

Models are found by loading every PHP file under `--models` and instantiating the
classes that extend `Anorm\Model` with a PDO, which is what `anorm make` writes. A
model whose constructor needs more than that is listed as skipped, with the reason,
rather than stopping the run.

## In code

The CLI is a thin wrapper, and the same comparison is available directly — useful in a
test that asserts your committed schema still matches your models:

```php
use Anorm\Schema\SchemaDiff;
use Anorm\Schema\SchemaInspector;

$models = ['App\Models\ClientModel' => new ClientModel($pdo)];
$diff = new SchemaDiff(new SchemaInspector($pdo), $models);

foreach ($diff->forModel(new HostingModel($pdo)) as $finding) {
    echo $finding->severity . ' ' . $finding->describe() . PHP_EOL;
}
```

`$models` is how a foreign key finding knows which table and key a relationship points
at. A related model that is not in it is reported as unchecked rather than guessed at.

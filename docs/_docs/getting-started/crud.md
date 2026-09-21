---
title: CRUD
category: Getting Started
order: 3
---

### Create and Update

Step 1. Define a model class

```php
use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model {
    public function __construct(Anorm $anorm)
    {
        parent::__construct($anorm->pdo, DataMapper::createByClass($anorm->pdo, $this));
        // Development only: lets Anorm create and alter tables to match the model.
        // Column types come from what the properties below declare, and are a guess
        // where they declare nothing — dump and correct the schema, then remove this
        // line, before production. See 'Schema modes'.
        $this->mapper()->mode = DataMapper::MODE_DYNAMIC;
    }

    /** @var integer The primary key */
    public $id;

    /** @var string Useful documentation about 'name' for intellisense */
    public $name;

}
```

Step 2. Use it to create a record in the database.

```php
$model = new SomeTableModel(Anorm::use('mydata'));
$model->name = 'bob';
$model->write();
```

### Read

```php
$model = new SomeTableModel(Anorm::use('mydata'));
$model->read($id);            // false when no row matched
$model->readOrThrow($id);     // throws "SomeTable id '3' not found"
```

The id you pass is bound as a parameter, so it is safe to hand `read()`,
`readOrThrow()`, `write()` and `delete()` a value that came straight from a
request — an arbitrary string matches no row rather than changing the query.
Anorm 3.2.0 and earlier concatenated the primary key into the WHERE clause of
`read()` and of the UPDATE issued by `write()`; upgrade to 3.2.1 or later.

Field *names* are a different matter. `byMango()` takes the field names in a
selector from its caller, and a name that is not in the model's map is passed
through as a column name. Anorm quotes it so it cannot break out of the
identifier, but an unchecked name can still address a column the caller was
not meant to see — whitelist selector keys against the model's map before
handing client input to `byMango()`.

### Delete

```php
$id = 3; // Likely passed on via GET or POST
$model = new SomeTableModel(Anorm::use('mydata'));
$model->id = $id;
$model->delete();
```

`delete()` returns `false` when no row matched. `deleteOrThrow()` turns that
into an exception instead:

```php
$model->id = $id;
$model->deleteOrThrow();   // throws "SomeTable id '3' not deleted"
```

Both throw if the primary key property is not set.

If you already hold the model — because you read it to check permissions, say
— just delete it:

```php
$model->readOrThrow($id);
$model->delete();
```

The mapper-level form still works and is the shortest thing to write, but it
cannot tell a delete listener which model was removed (see Lifecycle hooks):

```php
$model->mapper()->delete($id);
```

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

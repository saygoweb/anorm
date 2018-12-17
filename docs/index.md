---
title: Anorm TLDR;
---

**Anorm** is yet Another ORM for PHP from [SayGoWeb](https://saygoweb.com/).

> Check it out [on GitHub](https://github.com/saygoweb/anorm).

### Connecting

```php
$anorm = Anorm::connect('mydata', 'mysql:host=localhost;dbname=some_db', 'user', 'password');
```

then having connected you can fetch a reference to the connection with `use`.

```php
$anorm = Anorm::use('mydata');
```

### Create and Update

Step 1. Define a model class

```php
use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model {
    public function __construct(Anorm $anorm)
    {
        parent::__construct($anorm->pdo, DataMapper::createByClass($anorm->pdo, $this));
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

### Find Many

```php
$anorm = Anorm::use('mydata');
$data = DataMapper::find('SomeTableModel', $anorm->pdo)
    ->orderBy("name")
    ->limit(3)
    ->some();
foreach ($data as $model) {
    // ...
}
```

### Find One

```php
$anorm = Anorm::use('mydata');
$model = DataMapper::find('SomeTableModel', $anorm->pdo)
    ->where("`name`='Name 1'")
    ->one();
```

### Delete

```php
$id = 3; // Likely passed on via GET or POST
$model = new SomeTableModel(Anorm::use('mydata'));
$model->_mapper->delete($id);
```

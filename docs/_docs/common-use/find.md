---
title: Finding Data
category: Common Use
order: 4
---

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

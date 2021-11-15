---
title: Finding Data
category: Getting Started
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

### Find Many with Paging

Use the `limit` for paging `limit($recordCount, $offset)`. The example below returns 5 records starting at 100.

```php
$anorm = Anorm::use('mydata');
$data = DataMapper::find('SomeTableModel', $anorm->pdo)
    ->orderBy("name")
    ->limit(5, 100)
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

---
title: Connection
category: Common Use
order: 2
---

```php
$anorm = Anorm::connect('mydata', 'mysql:host=localhost;dbname=some_db', 'user', 'password');
```

then having connected you can fetch a reference to the connection with `use`.

```php
$anorm = Anorm::use('mydata');
```

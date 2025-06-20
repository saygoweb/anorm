# Anorm: Another ORM for PHP

[![Build Status](https://github.com/saygoweb/anorm/actions/workflows/php.yml/badge.svg?branch=master)](https://github.com/saygoweb/anorm/actions/workflows/php.yml?query=branch%3Amaster)
[![Coverage Status](https://coveralls.io/repos/github/saygoweb/anorm/badge.svg?branch=master)](https://coveralls.io/github/saygoweb/anorm?branch=master)
[![MIT Licence](https://badges.frapsoft.com/os/mit/mit.svg?v=103)](https://opensource.org/licenses/mit-license.php)

Yes, yet another ORM for PHP. This meets my needs for an ORM with the following characteristics:

* Works well with legacy databases.
* Provides (requires) a Model class which helps coding in IDEs.
* Creates and modifies the underlying database schema as required to match the Model.

## Features

* Provides a tool 'anorm' for quickly generating models from existing tables.
* Maps between camelCase property names and under_score field names common in database schema.
* Makes CRUD operations extremely simple.
* Doesn't get in the way of complex queries.

## Documentation

Documentation is available on the [docs site](https://saygoweb.github.io/anorm).
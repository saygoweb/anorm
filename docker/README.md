# docker/ — a PHP 7.4 stack for developing Anorm from the host

This directory gives you PHP 7.4 and MariaDB in containers while your editor,
your agent and your git checkout stay on the host. Commands reach into the
container; nothing about your workflow has to move inside it.

PHP **7.4** is the default because it is the floor Anorm supports, and the
version consumers on legacy stacks actually run. The CI matrix lists 7.4 but
has not been a reliable signal, so having the floor one command away matters.

## Quick start

```bash
docker/anorm init      # optional: pin this checkout to ports known to be free
docker/anorm up        # build the image, start MariaDB, install vendor/
docker/anorm test      # run the suite (composer test:quick)
```

The first `up` takes a few minutes: it builds the PHP image, compiles Xdebug
and runs `composer install`. Subsequent starts are seconds.

## Commands

`docker/anorm help` lists them all. The ones you will use:

| Command | What it does |
| --- | --- |
| `docker/anorm up [--build] [--tools]` | Start the stack; `--tools` adds phpMyAdmin |
| `docker/anorm down [-v]` | Stop it; `-v` also deletes the database volume |
| `docker/anorm test [args]` | `composer test:quick`, or PHPUnit with your args |
| `docker/anorm quality` | phpcs + phpstan |
| `docker/anorm coverage` | PHPUnit with Xdebug coverage into `build/coverage/` |
| `docker/anorm ci` | The full CI run: clover coverage, phpcs, phpstan |
| `docker/anorm shell` | Interactive bash in `/workspace` as you |
| `docker/anorm mysql` | mariadb client as root in the db container |
| `docker/anorm db-reset` | Drop and recreate the test database |
| `docker/anorm info` | PHP version, database port, credentials |

Arguments after `test` go straight to PHPUnit:

```bash
docker/anorm test --filter FieldSelection_Test
docker/anorm test test/anorm/Relationship
```

## Checking another PHP version

The image variant is a build arg, so the same checkout can be run against a
newer interpreter:

```bash
PHP_VARIANT=8.3-cli docker/anorm up --build
docker/anorm test
PHP_VARIANT=7.4-cli docker/anorm up --build   # back to the floor
```

This replaces the running container rather than adding a second one, and
`vendor/` is shared between them. If a dependency resolves differently on the
two versions, run `docker/anorm composer install` after switching.

## How it fits together

- **`Dockerfile`** — `php:7.4-cli` with `pdo_mysql`, `mysqli`, `mbstring`,
  `bcmath` and `zip`, plus Composer 2. Xdebug is pinned to 3.1.6 on 7.4,
  because the current release requires PHP 8 and would fail the build. A `dev`
  user is remapped to your UID/GID, so `vendor/` and `build/` written inside
  the container come back owned by you.
- **`docker-compose.yml`** — the `app` container (held open with
  `sleep infinity`; Anorm is a library, there is nothing to serve), MariaDB,
  and phpMyAdmin behind the `tools` profile. `DB_*` variables are set on `app`
  so `TestEnvironment::connect()` finds the stack's database — it reads
  environment variables before it reads the checkout's `.env`.
- **`scripts/entrypoint.sh`** — creates `build/coverage` and `build/logs`, then
  runs `composer install` on first start if `vendor/` is missing.
- **`php.ini`** — development error reporting, 512M memory limit (phpstan and
  coverage both need it), UTC, errors logged to `build/logs/php_errors.log`.
- **`xdebug.ini`** — Xdebug 3, `mode=off` by default. `coverage` and `ci` turn
  it on for that command only; for a debugger, use
  `XDEBUG_MODE=debug docker/anorm php ...`.
- **`docker/anorm`** — the driver. One stack per checkout by default (project
  name is `anorm-<directory>`), so worktrees do not collide.

## Configuration

Copy `.env.example` to `.env` to override anything, or run
`docker/anorm init`, which probes for free ports and writes them. `docker/.env`
is gitignored; `docker/.env.example` is the documentation.

The database defaults — `anorm_test`, user `dev`, password `dev`, root
password `root` — match `.devcontainer/docker-compose.yml`,
`.github/workflows/ci.yml` and the fallbacks in `TestEnvironment::connect()`.
Change them in all four places or in none.

## Relationship to `.devcontainer/`

`.devcontainer/` is still there and still works; it is for VS Code attaching
*into* a container. This stack is for the opposite arrangement — the tools stay
on the host. They use the same `/workspace` path and the same database
credentials, but separate volumes, so running both at once is safe. Only this
one publishes MariaDB to the host.

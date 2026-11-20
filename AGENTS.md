# Eloquent ClickHouse

Laravel Eloquent / query builder / schema driver for ClickHouse, published on Packagist as
`vaslv/eloquent-clickhouse`. It adapts the `smi2/phpclickhouse` HTTP client to Laravel's
PDO-shaped connection surface.

## Stack (check `composer.json` / `composer.lock`, do not guess)

- PHP `^8.2` (container default 8.4; CI matrix 8.2–8.4)
- `illuminate/database` `^12.0|^13.0` (Laravel 13 requires PHP 8.3+)
- `smi2/phpclickhouse` `^1.6`
- ClickHouse: CI service `clickhouse/clickhouse-server:25.3`; local stack in
  `docker-compose.yml`

## Commands

PHP and Composer run only inside the `php` container, never on the host.

- Start the stack: `make up`
- Install dependencies: `make install`
- Unit tests: `make test`; integration: `make test-integration`; both: `make test-all`
- Single test: `docker compose exec -T php vendor/bin/phpunit --filter=<Test>`
- Fix code style: `make lint`
- Gate before a commit or tag (validate, audit, Pint, PHPStan, all tests): `make check`
- Lowest dependencies and other PHP versions: see `docker/README.md`

`composer.lock` is not committed; it only pins the local toolchain.

## Releases

- Main branch: `main`
- Tag scheme: SemVer without the `v` prefix (`2.0.3`), annotated, message `Release <tag>`
- Release: move `## [Unreleased]` in `CHANGELOG.md` to the new version in the last commit;
  after a green CI run (including `--prefer-lowest`) tag inside the container, which has no
  git identity or SSH keys of its own:
  `docker compose exec -e GIT_COMMITTER_NAME="$(git config user.name)" -e GIT_COMMITTER_EMAIL="$(git config user.email)" php composer release`.
  Answer `N` to its push prompt and push from the host: `git push --atomic origin main <tag>`
- One-time per clone: `git config release.tagMessage 'Release {tag}'`

## Domain

- ClickHouse has no server-side prepared statements over HTTP: bindings are inlined with
  ClickHouse-safe escaping (`Concerns\EscapesClickHouseStrings`). Any change there needs
  injection tests.
- Transactions are no-ops; `update()`/`delete()` compile to `ALTER TABLE` mutations.
- Operations ClickHouse cannot run (`insertOrIgnore()`, `upsert()`, Postgres JSON wheres)
  throw instead of emitting SQL.
- `QueryGrammar` extends the Postgres grammar; check inherited compilers against ClickHouse
  syntax when bumping `illuminate/database`.
- PHPStan ignores in `phpstan.neon.dist` cover deliberate PDO-surface mismatches; they are
  version-sensitive, so PHPStan runs only on the highest dependencies.

## Exceptions

| Rule | Exception | Reason | Decided |
|---|---|---|---|
| — | none recorded | — | — |

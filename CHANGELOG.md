# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Public API surface

SemVer guarantees cover the following; everything else under `src/` is internal.

- Composer package `vaslv/eloquent-clickhouse` and the `clickhouse` database driver it
  registers through `Vaslv\EloquentClickHouse\ServiceProvider` (Laravel auto-discovery).
- Connection configuration keys documented in the README (host, port, database,
  credentials, TLS and timeout options, `read`/`write` blocks, `engine`).
- Public methods of `Vaslv\EloquentClickHouse\ClickHouseConnection`.
- Query builder, Eloquent and schema builder behaviour documented in the README,
  including the operations that deliberately throw.

## [Unreleased]

## [2.0.4] - 2026-11-20

### Changed

- Integration tests fail instead of being skipped when `CLICKHOUSE_HOST` is not set.
- Development tooling: `composer lint`, `composer lint:test` and `composer check` scripts,
  `phpunit.xml.dist`, `pint.json`, `.editorconfig`; CI also runs `composer audit`
  (abandoned packages are reported, not failed) and Pint.

### Security

- GitHub Actions run with a read-only token and without persisted checkout credentials.

## [2.0.3] - 2026-08-14

### Changed

- `phpstan.neon.dist` is excluded from the dist archive.

## [2.0.2] - 2026-07-21

### Added

- PHPStan static analysis at level 6, run in a dedicated CI job.

## [2.0.1] - 2026-07-21

### Added

- Read/write connection splitting.
- `cursor()` streams rows instead of materializing the whole result.
- Unsupported Postgres-inherited operations (`insertOrIgnore()`, `upsert()`, JSON wheres)
  throw a clear exception.

### Fixed

- Value and identifier inlining is correct and injection-safe.
- Nullable columns wrapped in `LowCardinality` are detected.
- `cursor()` falls back to an eager fetch when the installed smi2 client lacks
  `selectGenerator()`.

## [2.0.0] - 2026-07-21

### Changed

- **Breaking:** the package is renamed to `vaslv/eloquent-clickhouse` and the namespace to
  `Vaslv\EloquentClickHouse`. Update `composer require` and imports.

## [1.2.2] - 2026-06-04

### Fixed

- Shell quoting in the CI Illuminate version check.

## [1.2.1] - 2026-06-04

### Added

- Russian README.

## [1.2.0] - 2026-06-04

### Added

- ClickHouse schema grammar, schema builder and post-processor.
- `update()` and `delete()` compile to `ALTER TABLE` mutations.
- PHP arrays compile to ClickHouse `Array` and `Map` literals.
- TLS and timeout options are forwarded to the smi2 client.

### Fixed

- Bindings are honoured and values are escaped ClickHouse-safely.
- `DateTimeInterface` values are escaped in `parameter()` (SQL injection).
- `statement()`, `unprepared()`, `affectingStatement()` and `cursor()` go through the client
  and `run()`, so logging, events and `QueryException` work.
- Date and time where clauses compile correctly.
- The configured database is selected on the client.
- Reconnect and disconnect take effect.
- Only ClickHouse-supported query operators are advertised.

### Removed

- The hardcoded `version` field in `composer.json`.

## [1.1.2] - 2026-04-23

### Changed

- IDE inspection fixes in tests and the workflow.

## [1.1.1] - 2026-04-23

### Changed

- Code formatted with Pint.

## [1.1.0] - 2026-04-23

### Added

- Laravel 12 and 13 compatibility checks.

### Fixed

- `ClickHouseConnection::select()` signature matches Laravel 12 and 13.

## [1.0.2] - 2026-03-31

### Changed

- `composer.json` metadata updates.

## [1.0.1] - 2025-09-10

### Added

- Initial release.

[Unreleased]: https://github.com/vaslv/eloquent-clickhouse/compare/2.0.4...HEAD
[2.0.4]: https://github.com/vaslv/eloquent-clickhouse/compare/2.0.3...2.0.4
[2.0.3]: https://github.com/vaslv/eloquent-clickhouse/compare/2.0.2...2.0.3
[2.0.2]: https://github.com/vaslv/eloquent-clickhouse/compare/2.0.1...2.0.2
[2.0.1]: https://github.com/vaslv/eloquent-clickhouse/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/vaslv/eloquent-clickhouse/compare/1.2.2...2.0.0
[1.2.2]: https://github.com/vaslv/eloquent-clickhouse/compare/1.2.1...1.2.2
[1.2.1]: https://github.com/vaslv/eloquent-clickhouse/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/vaslv/eloquent-clickhouse/compare/1.1.2...1.2.0
[1.1.2]: https://github.com/vaslv/eloquent-clickhouse/compare/1.1.1...1.1.2
[1.1.1]: https://github.com/vaslv/eloquent-clickhouse/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/vaslv/eloquent-clickhouse/compare/1.0.2...1.1.0
[1.0.2]: https://github.com/vaslv/eloquent-clickhouse/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/vaslv/eloquent-clickhouse/releases/tag/1.0.1

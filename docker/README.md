# Local development environment (Docker)

A self-contained stack for developing and testing the package against a **real ClickHouse server**.
It is isolated from any other ClickHouse you may already run (host ports `18123`/`19000`, dedicated
volumes and network).

## Services

| Service | Image | Purpose |
| --- | --- | --- |
| `clickhouse` | `clickhouse/clickhouse-server` | Real ClickHouse for integration tests (HTTP `18123`, native `19000` on host) |
| `php` | `docker/php/Dockerfile` (PHP 8.4 + Composer) | Runs Composer, PHPUnit and Pint |

Connection defaults (overridable via env): database `eloquent_test`, user `eloquent`, password `secret`.

## Quick start

```bash
make up            # build + start ClickHouse and PHP (waits for ClickHouse health)
make install       # composer install inside the container
make test          # unit tests
make test-integration   # integration tests against the live ClickHouse
make test-all      # both suites
```

Without `make`, the same via Compose directly:

```bash
docker compose up -d --build
docker compose exec -T php composer install
docker compose exec -T php composer test
docker compose exec -T php composer test:integration
```

Other helpers: `make sh` (shell in PHP container), `make ch` (ClickHouse client),
`make logs`, `make down`, `make clean` (also drops the data volume).

## Test suites

- **unit** (`composer test`) — no database required; reflection-based compatibility checks.
  This is what CI runs.
- **integration** (`composer test:integration`) — talks to the live ClickHouse server.
  Tests skip automatically when `CLICKHOUSE_HOST` is unset, so the unit suite runs anywhere.

## PHP / Laravel matrix

Rebuild the PHP container for another version, then update dependencies:

```bash
docker compose build --build-arg PHP_VERSION=8.3
docker compose run --rm php composer update            # Laravel 13 (latest)
docker compose run --rm php composer update --prefer-lowest --with="illuminate/database:^12.0"
```

## Reproducibility note

`docker-compose.yml` uses `clickhouse/clickhouse-server:latest` to reuse a locally cached image.
Pin a concrete tag (e.g. `:25.3`) when you need byte-for-byte reproducibility.

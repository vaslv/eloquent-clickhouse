# Eloquent ClickHouse

![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)
![CI](https://img.shields.io/badge/CI-compatibility%20checks-2EA44F)

Laravel Eloquent driver for ClickHouse.

## Compatibility

| Package | Supported versions |
| --- | --- |
| PHP | 8.2+ |
| Laravel / `illuminate/database` | 12.x, 13.x |
| ClickHouse client | `smi2/phpclickhouse` 1.6+ |

Compatibility is covered by automated checks for:

- Laravel 12 lowest supported dependencies
- Laravel 13 latest dependencies

See [.github/workflows/compatibility.yml](./.github/workflows/compatibility.yml).

## Installation

```bash
composer require timeleads/eloquent-clickhouse
```

## Configuration

Add a ClickHouse connection to `config/database.php`:

```php
'connections' => [
    'clickhouse' => [
        'driver' => 'clickhouse',
        'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port' => env('CLICKHOUSE_PORT', 8123),
        'database' => env('CLICKHOUSE_DATABASE', 'default'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'prefix' => '',
        'settings' => [
            // 'max_execution_time' => 60,
        ],
    ],
],
```

Example `.env` values:

```dotenv
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
```

## Usage

### Query Builder

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('clickhouse')
    ->table('events')
    ->whereDate('created_at', now()->toDateString())
    ->get();
```

### Raw Select

```php
$rows = DB::connection('clickhouse')
    ->select('SELECT * FROM events LIMIT 10');
```

### Raw Insert

```php
DB::connection('clickhouse')
    ->insert("INSERT INTO events (id, name) VALUES (1, 'test')");
```

## Notes

- The package registers the `clickhouse` database driver through its service provider.
- `select()` returns rows from `smi2/phpclickhouse`.
- Transactions are no-op methods because ClickHouse does not provide transactional behavior like traditional OLTP databases.
- The grammar is intentionally minimal and currently focuses on query execution rather than full Laravel schema support.

## Development

Run compatibility checks locally:

```bash
composer update
composer test
```

To verify the lowest supported Laravel 12 dependency set:

```bash
composer update --prefer-lowest
composer test
```

## License

MIT

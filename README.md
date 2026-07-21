# Eloquent ClickHouse

**English** | [Русский](README.ru.md)

[![Packagist Version](https://img.shields.io/packagist/v/vaslv/eloquent-clickhouse)](https://packagist.org/packages/vaslv/eloquent-clickhouse)
[![CI](https://github.com/vaslv/eloquent-clickhouse/actions/workflows/compatibility.yml/badge.svg)](https://github.com/vaslv/eloquent-clickhouse/actions/workflows/compatibility.yml)
[![Plumb score](https://plumbphp.dev/badges/vaslv/eloquent-clickhouse/composite.svg)](https://plumbphp.dev/vaslv/eloquent-clickhouse)
![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)

Laravel Eloquent driver for ClickHouse.

## Compatibility

| Package | Supported versions |
| --- | --- |
| PHP | 8.2+ |
| Laravel / `illuminate/database` | 12.x, 13.x |
| ClickHouse client | `smi2/phpclickhouse` 1.6+ |

Compatibility is covered by automated checks (unit + live-ClickHouse integration tests) for:

- Laravel 12 on PHP 8.2 (lowest supported dependencies), 8.3 and 8.4
- Laravel 13 on PHP 8.3 and 8.4

See [.github/workflows/compatibility.yml](./.github/workflows/compatibility.yml).

## Installation

```bash
composer require vaslv/eloquent-clickhouse
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
            // Server settings sent with every query, e.g.:
            // 'max_execution_time' => 60,
            // 'mutations_sync' => 1, // make update()/delete() wait for the mutation
        ],
        // Optional:
        // 'engine' => 'MergeTree',   // default table engine for migrations
        // 'timeout' => 30,           // query timeout, seconds (max_execution_time)
        // 'connect_timeout' => 5.0,  // connection timeout, seconds
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

### TLS

```php
'clickhouse' => [
    // ...
    'port' => 8443,
    'https' => true,
    // 'sslCA' => '/path/to/ca.pem', // custom CA bundle ('ssl_ca' also accepted)
    // 'verify' => false,            // disable certificate verification (not recommended)
    // 'curl_options' => [CURLOPT_SSL_VERIFYHOST => 0], // raw overrides, win over the defaults
],
```

With `'https' => true` the server certificate is **verified by default** (the underlying
smi2 client on its own disables verification). Note that the connector's initial `ping()`
goes through an smi2 code path that never verifies certificates; all real queries do.

## Usage

### Query Builder

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('clickhouse')
    ->table('events')
    ->whereDate('created_at', now()->toDateString())
    ->get();
```

### Updates and deletes

`update()` and `delete()` compile to ClickHouse mutations:

```php
DB::connection('clickhouse')->table('events')->where('id', 1)->update(['name' => 'x']);
// ALTER TABLE "events" UPDATE "name" = 'x' WHERE "id" = 1

DB::connection('clickhouse')->table('events')->where('id', 1)->delete();
// ALTER TABLE "events" DELETE WHERE "id" = 1

DB::connection('clickhouse')->table('events')->delete(); // no WHERE
// TRUNCATE TABLE "events" — an unconditional delete truncates instead of mutating
```

Caveats inherent to ClickHouse:

- Mutations require a MergeTree-family engine. Log-family and Memory tables accept
  inserts and `truncate()`, but reject `update()`/`delete()`-with-`where`.
- Mutations are **asynchronous** by default: a read issued immediately after
  `update()`/`delete()` may see old data. Set `'settings' => ['mutations_sync' => 1]`
  to make them synchronous.
- The HTTP interface reports no affected-row count, so `update()`, `delete()`,
  `increment()` and `decrement()` always return `0`, and `updateOrInsert()` returns
  `false` on its update branch (the write itself succeeds).
- `insertGetId()` and `upsert()` throw: ClickHouse has no auto-increment, `RETURNING`
  or `ON CONFLICT`. Use explicit keys (e.g. UUIDs) and `insert()`; deduplicate with a
  `ReplacingMergeTree` engine where needed.

### Migrations

```php
Schema::connection('clickhouse')->create('events', function (Blueprint $table) {
    $table->engine('MergeTree');          // default: MergeTree (or the 'engine' config)
    $table->uuid('id');
    $table->string('name')->default('');
    $table->dateTime('created_at', 3);
    $table->primary(['created_at', 'id']); // becomes ORDER BY (sorting key)
});
```

- The blueprint's `primary()` becomes the MergeTree `ORDER BY` sorting key; without one
  the table is created with `ORDER BY tuple()`. An engine string may carry its own
  clause: `$table->engine('MergeTree ORDER BY (id)')`.
- `hasTable()`, `hasColumn()`, `getTables()`, `getColumns()`, `getViews()`,
  `dropAllTables()` (and therefore `migrate:fresh`) work against
  `system.tables` / `system.columns`.
- Column types map to ClickHouse-native ones (`string` → `String`,
  `unsignedBigInteger` → `UInt64`, `dateTime($p)` → `DateTime64($p)`, `boolean` →
  `Bool`, `uuid` → `UUID`, `enum` → `Enum8`, ...). `nullable()` wraps the type in
  `Nullable(...)`.
- Unsupported concepts fail loudly with a `RuntimeException` instead of being silently
  skipped: auto-increment (`id()`/`increments()`), indexes, unique/foreign keys,
  `time()` columns, nullable columns inside the sorting key.

### Array and Map values

PHP arrays compile to ClickHouse-native literals: lists become `Array` literals,
associative arrays become `Map` construction calls — in inserts, wheres, updates
and raw bindings alike:

```php
DB::connection('clickhouse')->table('events')->insert([
    'id' => 1,
    'tags' => ['alpha', 'beta'],          // -> ['alpha', 'beta']
    'attrs' => ['region' => 'eu'],        // -> map('region', 'eu')
]);

DB::connection('clickhouse')->table('events')->where('tags', ['alpha', 'beta'])->get();

DB::connection('clickhouse')->select('SELECT arrayConcat(?, ?) AS merged', [['a'], ['b']]);
```

### Raw statements

```php
$rows = DB::connection('clickhouse')->select('SELECT * FROM events LIMIT 10');

DB::connection('clickhouse')->insert("INSERT INTO events (id, name) VALUES (1, 'test')");

DB::connection('clickhouse')->statement('OPTIMIZE TABLE events FINAL');
```

## Notes

- The package registers the `clickhouse` database driver through its service provider.
- `select()` returns rows from `smi2/phpclickhouse` as associative arrays.
- Values are inlined into SQL with ClickHouse-safe escaping (the HTTP interface has no
  server-side prepared statements).
- Transactions are no-op methods because ClickHouse does not provide transactional
  behavior like traditional OLTP databases.

## Development

A Docker test stack (PHP + live ClickHouse) is included:

```bash
make up               # start ClickHouse + PHP containers
make test             # unit tests
make test-integration # integration tests against the live ClickHouse
make test-all         # both
```

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

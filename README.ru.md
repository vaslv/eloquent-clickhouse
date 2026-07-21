# Eloquent ClickHouse

[English](README.md) | **Русский**

[![Packagist Version](https://img.shields.io/packagist/v/vaslv/eloquent-clickhouse)](https://packagist.org/packages/vaslv/eloquent-clickhouse)
[![CI](https://github.com/vaslv/eloquent-clickhouse/actions/workflows/compatibility.yml/badge.svg)](https://github.com/vaslv/eloquent-clickhouse/actions/workflows/compatibility.yml)
[![Plumb score](https://plumbphp.dev/badges/vaslv/eloquent-clickhouse/composite.svg)](https://plumbphp.dev/vaslv/eloquent-clickhouse)
![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)

Laravel Eloquent драйвер для ClickHouse.

## Совместимость

| Пакет | Поддерживаемые версии |
| --- | --- |
| PHP | 8.2+ |
| Laravel / `illuminate/database` | 12.x, 13.x |
| ClickHouse-клиент | `smi2/phpclickhouse` 1.6+ |

Совместимость покрыта автоматическими проверками (юнит-тесты + интеграционные тесты
против живого ClickHouse) для:

- Laravel 12 на PHP 8.2 (минимальные версии зависимостей), 8.3 и 8.4
- Laravel 13 на PHP 8.3 и 8.4

См. [.github/workflows/compatibility.yml](./.github/workflows/compatibility.yml).

## Установка

```bash
composer require vaslv/eloquent-clickhouse
```

## Настройка

Добавьте подключение ClickHouse в `config/database.php`:

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
            // Серверные настройки, отправляемые с каждым запросом, например:
            // 'max_execution_time' => 60,
            // 'mutations_sync' => 1, // update()/delete() ждут завершения мутации
        ],
        // Опционально:
        // 'engine' => 'MergeTree',   // движок таблиц по умолчанию для миграций
        // 'timeout' => 30,           // таймаут запроса, секунды (max_execution_time)
        // 'connect_timeout' => 5.0,  // таймаут подключения, секунды
    ],
],
```

Пример значений в `.env`:

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
    // 'sslCA' => '/path/to/ca.pem', // свой CA-бандл (принимается и 'ssl_ca')
    // 'verify' => false,            // отключить проверку сертификата (не рекомендуется)
    // 'curl_options' => [CURLOPT_SSL_VERIFYHOST => 0], // сырые curl-опции, важнее значений по умолчанию
],
```

При `'https' => true` сертификат сервера **проверяется по умолчанию** (сам по себе
smi2-клиент проверку отключает). Учтите: первоначальный `ping()` коннектора идёт через
код smi2, который никогда не проверяет сертификаты; все реальные запросы — проверяют.

## Использование

### Query Builder

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('clickhouse')
    ->table('events')
    ->whereDate('created_at', now()->toDateString())
    ->get();
```

### Update и delete

`update()` и `delete()` компилируются в мутации ClickHouse:

```php
DB::connection('clickhouse')->table('events')->where('id', 1)->update(['name' => 'x']);
// ALTER TABLE "events" UPDATE "name" = 'x' WHERE "id" = 1

DB::connection('clickhouse')->table('events')->where('id', 1)->delete();
// ALTER TABLE "events" DELETE WHERE "id" = 1

DB::connection('clickhouse')->table('events')->delete(); // без WHERE
// TRUNCATE TABLE "events" — безусловный delete делает truncate, а не мутацию
```

Ограничения, присущие самому ClickHouse:

- Мутации требуют движок семейства MergeTree. Таблицы Log-семейства и Memory
  принимают вставки и `truncate()`, но отвергают `update()`/`delete()` с `where`.
- Мутации по умолчанию **асинхронны**: чтение сразу после `update()`/`delete()`
  может увидеть старые данные. Задайте `'settings' => ['mutations_sync' => 1]`,
  чтобы сделать их синхронными.
- HTTP-интерфейс не сообщает число затронутых строк, поэтому `update()`, `delete()`,
  `increment()` и `decrement()` всегда возвращают `0`, а `updateOrInsert()` возвращает
  `false` на update-ветке (сама запись при этом выполняется).
- `insertGetId()` и `upsert()` бросают исключение: в ClickHouse нет auto-increment,
  `RETURNING` и `ON CONFLICT`. Используйте явные ключи (например, UUID) и `insert()`;
  для дедупликации — движок `ReplacingMergeTree`.

### Миграции

```php
Schema::connection('clickhouse')->create('events', function (Blueprint $table) {
    $table->engine('MergeTree');          // по умолчанию: MergeTree (или 'engine' из конфига)
    $table->uuid('id');
    $table->string('name')->default('');
    $table->dateTime('created_at', 3);
    $table->primary(['created_at', 'id']); // становится ORDER BY (ключ сортировки)
});
```

- `primary()` блюпринта становится ключом сортировки MergeTree (`ORDER BY`); без него
  таблица создаётся с `ORDER BY tuple()`. Строка движка может нести свой собственный
  ключ: `$table->engine('MergeTree ORDER BY (id)')`.
- `hasTable()`, `hasColumn()`, `getTables()`, `getColumns()`, `getViews()`,
  `dropAllTables()` (а значит, и `migrate:fresh`) работают через
  `system.tables` / `system.columns`.
- Типы колонок маппятся на нативные типы ClickHouse (`string` → `String`,
  `unsignedBigInteger` → `UInt64`, `dateTime($p)` → `DateTime64($p)`, `boolean` →
  `Bool`, `uuid` → `UUID`, `enum` → `Enum8`, ...). `nullable()` оборачивает тип в
  `Nullable(...)`.
- Неподдерживаемые концепции громко падают с `RuntimeException`, а не пропускаются
  молча: auto-increment (`id()`/`increments()`), индексы, unique/foreign-ключи,
  колонки `time()`, nullable-колонки в ключе сортировки.

### Значения Array и Map

PHP-массивы компилируются в нативные литералы ClickHouse: списки — в литералы
`Array`, ассоциативные массивы — в вызовы конструктора `Map`. Работает в insert,
where, update и сырых биндингах:

```php
DB::connection('clickhouse')->table('events')->insert([
    'id' => 1,
    'tags' => ['alpha', 'beta'],          // -> ['alpha', 'beta']
    'attrs' => ['region' => 'eu'],        // -> map('region', 'eu')
]);

DB::connection('clickhouse')->table('events')->where('tags', ['alpha', 'beta'])->get();

DB::connection('clickhouse')->select('SELECT arrayConcat(?, ?) AS merged', [['a'], ['b']]);
```

### Сырые запросы

```php
$rows = DB::connection('clickhouse')->select('SELECT * FROM events LIMIT 10');

DB::connection('clickhouse')->insert("INSERT INTO events (id, name) VALUES (1, 'test')");

DB::connection('clickhouse')->statement('OPTIMIZE TABLE events FINAL');
```

## Примечания

- Пакет регистрирует драйвер базы данных `clickhouse` через свой сервис-провайдер.
- `select()` возвращает строки из `smi2/phpclickhouse` как ассоциативные массивы.
- Значения инлайнятся в SQL с ClickHouse-безопасным экранированием (HTTP-интерфейс
  не поддерживает серверные prepared statements). Поскольку значения инлайнятся, они
  попадают и в `toSql()`, в лог запросов и в тексты исключений — учитывайте это для
  секретов и персональных данных.
- Значения `DateTimeInterface` форматируются до целых секунд (`Y-m-d H:i:s`). Колонка
  `DateTime` (посекундная) в ClickHouse отвергает строку с долями секунды, а Carbon всегда
  несёт микросекунды, поэтому доли секунды не пишутся даже в колонки `DateTime64`. Чтобы
  сохранить субсекундную точность, передайте готовую строку (например, `->format('Y-m-d H:i:s.u')`).
- Поддерживается разделение соединений read/write: конфиг с блоками `read`/`write`
  направляет `useReadPdo`-чтения на read-хост, а записи — на write-хост.
- `cursor()` стримит строки по одной (`selectGenerator` из smi2), поэтому остаётся
  ограниченным по памяти на больших выборках.
- `insertOrIgnore()`, `upsert()` и JSON-where в стиле Postgres (`whereJsonContains`,
  стрелочный селектор `->`, ...) бросают понятное исключение, а не компилируются в SQL,
  который ClickHouse не выполнит. Для JSON используйте сырой where с функциями ClickHouse
  `JSONExtractString` / `JSONHas`.
- Методы транзакций — no-op: ClickHouse не предоставляет транзакционного поведения,
  как традиционные OLTP-базы.

## Разработка

В комплекте Docker-стек для тестов (PHP + живой ClickHouse):

```bash
make up               # поднять контейнеры ClickHouse + PHP
make test             # юнит-тесты
make test-integration # интеграционные тесты против живого ClickHouse
make test-all         # и те, и другие
```

Локальные проверки совместимости:

```bash
composer update
composer test
```

Проверка минимального набора зависимостей Laravel 12:

```bash
composer update --prefer-lowest
composer test
```

## Лицензия

MIT

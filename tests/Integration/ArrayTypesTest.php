<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ClickHouseConnector;

/**
 * Live tests for PHP array values: lists compile to ClickHouse Array literals,
 * associative arrays to Map construction calls, through insert/where/update.
 */
final class ArrayTypesTest extends TestCase
{
    private const TABLE = 'array_types_test';

    private ClickHouseConnection $connection;

    protected function setUp(): void
    {
        if (! getenv('CLICKHOUSE_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_HOST not set — integration environment unavailable.');
        }

        $config = [
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            'database' => getenv('CLICKHOUSE_DATABASE') ?: 'default',
            'username' => getenv('CLICKHOUSE_USERNAME') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: '',
            'settings' => ['mutations_sync' => 1],
        ];

        $client = (new ClickHouseConnector)->connect($config);
        $this->connection = new ClickHouseConnection($client, $config['database'], '', $config);

        $this->connection->statement('DROP TABLE IF EXISTS '.self::TABLE);
        $this->connection->statement(
            'CREATE TABLE '.self::TABLE.' (id UInt64, tags Array(String), attrs Map(String, String)) '
            .'ENGINE = MergeTree ORDER BY id',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->statement('DROP TABLE IF EXISTS '.self::TABLE);
        }
    }

    public function test_insert_and_read_back_array_and_map_columns(): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => 1,
            'tags' => ['alpha', "o'meta", 'beta\\gamma'],
            'attrs' => ['region' => 'eu', 'tier' => 'gold'],
        ]);

        $row = $this->connection->table(self::TABLE)->first();

        self::assertSame(['alpha', "o'meta", 'beta\\gamma'], $row['tags']);
        self::assertSame(['region' => 'eu', 'tier' => 'gold'], (array) $row['attrs']);
    }

    public function test_empty_array_round_trips(): void
    {
        $this->connection->table(self::TABLE)->insert(['id' => 1, 'tags' => []]);

        self::assertSame([], $this->connection->table(self::TABLE)->value('tags'));
    }

    public function test_where_equality_against_an_array_column(): void
    {
        $this->connection->table(self::TABLE)->insert([
            ['id' => 1, 'tags' => ['a']],
            ['id' => 2, 'tags' => ['a', 'b']],
        ]);

        $id = $this->connection->table(self::TABLE)->where('tags', ['a', 'b'])->value('id');

        self::assertSame(2, (int) $id);
    }

    public function test_array_binding_in_raw_select(): void
    {
        $rows = $this->connection->select('SELECT arrayConcat(?, ?) AS merged', [['a'], ['b', 'c']]);

        self::assertSame(['a', 'b', 'c'], $rows[0]['merged']);
    }

    public function test_update_mutation_replaces_an_array_value(): void
    {
        $this->connection->table(self::TABLE)->insert(['id' => 1, 'tags' => ['old']]);

        $this->connection->table(self::TABLE)->where('id', 1)->update(['tags' => ['new', 'fresh']]);

        self::assertSame(['new', 'fresh'], $this->connection->table(self::TABLE)->value('tags'));
    }
}

<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ClickHouseConnector;

/**
 * Live tests for query-builder update()/delete()/truncate(), which compile to
 * ALTER TABLE mutations / TRUNCATE. mutations_sync=1 makes ClickHouse wait for the
 * (normally asynchronous) mutation so the assertions can read the result back.
 */
final class MutationsTest extends TestCase
{
    private const TABLE = 'mutations_test';

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
            'CREATE TABLE '.self::TABLE.' (id UInt64, name String) ENGINE = MergeTree ORDER BY id',
        );

        $this->connection->table(self::TABLE)->insert([
            ['id' => 1, 'name' => 'one'],
            ['id' => 2, 'name' => 'two'],
            ['id' => 3, 'name' => 'three'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->statement('DROP TABLE IF EXISTS '.self::TABLE);
        }
    }

    public function test_update_mutates_matching_rows(): void
    {
        $this->connection->table(self::TABLE)->where('id', 2)->update(['name' => "o'two"]);

        $names = $this->connection->table(self::TABLE)->orderBy('id')->pluck('name')->all();

        self::assertSame(['one', "o'two", 'three'], $names);
    }

    public function test_update_or_insert_updates_through_the_defensive_limit(): void
    {
        $this->connection->table(self::TABLE)->updateOrInsert(['id' => 3], ['name' => 'tres']);

        self::assertSame(
            'tres',
            $this->connection->table(self::TABLE)->where('id', 3)->value('name'),
        );
    }

    public function test_delete_by_id_strips_the_qualified_key_column(): void
    {
        // Builder::delete($id) injects a "table.id" where; ClickHouse mutation
        // predicates reject qualified columns, so the grammar must strip them.
        $this->connection->table(self::TABLE)->delete(2);

        $ids = $this->connection->table(self::TABLE)->orderBy('id')->pluck('id')->all();

        self::assertSame([1, 3], array_map(intval(...), $ids));
    }

    public function test_delete_with_where_removes_matching_rows(): void
    {
        $this->connection->table(self::TABLE)->where('id', '>', 1)->delete();

        $rows = $this->connection->table(self::TABLE)->get();

        self::assertCount(1, $rows);
        self::assertSame('one', $rows[0]['name']);
    }

    public function test_unconditional_delete_truncates(): void
    {
        $this->connection->table(self::TABLE)->delete();

        self::assertSame(0, (int) $this->connection->table(self::TABLE)->count());
    }

    public function test_truncate_empties_the_table(): void
    {
        $this->connection->table(self::TABLE)->truncate();

        self::assertSame(0, (int) $this->connection->table(self::TABLE)->count());
    }
}

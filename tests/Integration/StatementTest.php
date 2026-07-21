<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests\Integration;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnection;
use Vaslv\EloquentClickHouse\ClickHouseConnector;

/**
 * statement()/unprepared()/cursor() must route to the smi2 client instead of inheriting the
 * base Connection implementations, which call PDO::prepare()/exec() on the non-PDO client.
 */
final class StatementTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('CLICKHOUSE_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_HOST not set — integration environment unavailable.');
        }
    }

    /**
     * @return array{host:string,port:int,database:string,username:string,password:string}
     */
    private function config(): array
    {
        return [
            'host' => getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            'database' => getenv('CLICKHOUSE_DATABASE') ?: 'default',
            'username' => getenv('CLICKHOUSE_USERNAME') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: '',
        ];
    }

    private function connection(): ClickHouseConnection
    {
        $config = $this->config();
        $client = (new ClickHouseConnector)->connect($config);

        return new ClickHouseConnection($client, $config['database'], '', $config);
    }

    public function test_statement_executes_ddl(): void
    {
        $conn = $this->connection();

        try {
            $ok = $conn->statement('CREATE TABLE IF NOT EXISTS ec_statement (id UInt32) ENGINE = Memory');

            self::assertTrue($ok);

            $rows = $conn->select("SELECT count() AS c FROM system.tables WHERE name = 'ec_statement'");
            self::assertSame(1, (int) $rows[0]['c']);
        } finally {
            $conn->statement('DROP TABLE IF EXISTS ec_statement');
        }
    }

    public function test_unprepared_executes(): void
    {
        $conn = $this->connection();

        try {
            self::assertTrue($conn->unprepared('CREATE TABLE IF NOT EXISTS ec_unprepared (id UInt32) ENGINE = Memory'));

            $rows = $conn->select("SELECT count() AS c FROM system.tables WHERE name = 'ec_unprepared'");
            self::assertSame(1, (int) $rows[0]['c']);
        } finally {
            $conn->statement('DROP TABLE IF EXISTS ec_unprepared');
        }
    }

    public function test_cursor_streams_rows(): void
    {
        $values = [];

        foreach ($this->connection()->cursor('SELECT number FROM numbers(3)') as $row) {
            $values[] = (int) $row['number'];
        }

        self::assertSame([0, 1, 2], $values);
    }

    public function test_cursor_wraps_failures_in_query_exception(): void
    {
        $this->expectException(QueryException::class);

        iterator_to_array($this->connection()->cursor('SELECT broken syntax ('));
    }

    public function test_cursor_queries_are_logged(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        iterator_to_array($connection->cursor('SELECT 1 AS n'));

        self::assertCount(1, $connection->getQueryLog());
        self::assertSame('SELECT 1 AS n', $connection->getQueryLog()[0]['query']);
    }
}

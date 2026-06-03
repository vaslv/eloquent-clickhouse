<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ClickHouseConnector;

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
}

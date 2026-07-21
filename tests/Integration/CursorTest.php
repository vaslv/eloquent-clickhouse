<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnection;
use Vaslv\EloquentClickHouse\ClickHouseConnector;

/**
 * cursor() must stream every row correctly through smi2's selectGenerator — the row it
 * primes inside run() must not be dropped, and all rows must arrive in order.
 */
final class CursorTest extends TestCase
{
    private ClickHouseConnection $connection;

    protected function setUp(): void
    {
        if (! getenv('CLICKHOUSE_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_HOST not set — integration environment unavailable.');
        }

        $config = [
            'host' => getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            'database' => getenv('CLICKHOUSE_DATABASE') ?: 'default',
            'username' => getenv('CLICKHOUSE_USERNAME') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: '',
        ];

        $client = (new ClickHouseConnector)->connect($config);
        $this->connection = new ClickHouseConnection($client, $config['database'], '', $config);

        $this->connection->statement('DROP TABLE IF EXISTS cursor_probe');
        $this->connection->statement('CREATE TABLE cursor_probe (n UInt32) ENGINE = Memory');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->statement('DROP TABLE IF EXISTS cursor_probe');
        }
    }

    public function test_cursor_streams_all_rows_in_order(): void
    {
        foreach (range(0, 9) as $n) {
            $this->connection->table('cursor_probe')->insert(['n' => $n]);
        }

        $seen = [];
        foreach ($this->connection->cursor('SELECT n FROM cursor_probe ORDER BY n') as $row) {
            $seen[] = (int) $row['n'];
        }

        self::assertSame(range(0, 9), $seen);
    }

    public function test_cursor_over_empty_result_yields_nothing(): void
    {
        $seen = [];
        foreach ($this->connection->cursor('SELECT n FROM cursor_probe') as $row) {
            $seen[] = $row;
        }

        self::assertSame([], $seen);
    }
}

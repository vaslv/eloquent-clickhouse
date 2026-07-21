<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests\Integration;

use ClickHouseDB\Client;
use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnection;
use Vaslv\EloquentClickHouse\ClickHouseConnector;

/**
 * The connection must route useReadPdo selects to a separately-configured read client
 * (lazily resolved) while writes stay on the write client — the wiring the service
 * provider sets up for a config with read/write blocks.
 */
final class ReadWriteConnectionTest extends TestCase
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

    public function test_read_pdo_is_resolved_lazily_and_used_for_reads(): void
    {
        $config = $this->config();
        $connector = new ClickHouseConnector;
        $connection = new ClickHouseConnection($connector->connect($config), $config['database'], '', $config);

        $resolved = 0;
        $connection->setReadPdo(function () use (&$resolved, $connector, $config): Client {
            $resolved++;

            return $connector->connect($config);
        });

        // No read query issued yet: the read client must not have been built.
        self::assertSame(0, $resolved);

        // useReadPdo defaults to true, so this select must go through the read client.
        $rows = $connection->select('SELECT 1 AS n');

        self::assertSame(1, (int) $rows[0]['n']);
        self::assertSame(1, $resolved, 'read client should be resolved exactly once, on first read');
    }
}

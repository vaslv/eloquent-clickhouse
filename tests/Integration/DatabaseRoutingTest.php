<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnection;
use Vaslv\EloquentClickHouse\ClickHouseConnector;

/**
 * The configured database must actually be selected on the wire. The smi2 client ignores a
 * 'database' connect param (it defaults to 'default'), so the connector has to set it explicitly.
 */
final class DatabaseRoutingTest extends TestCase
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

    public function test_configured_database_is_used(): void
    {
        $config = $this->config();
        $client = (new ClickHouseConnector)->connect($config);
        $connection = new ClickHouseConnection($client, $config['database'], '', $config);

        $rows = $connection->select('SELECT currentDatabase() AS db');

        self::assertSame($config['database'], (string) $rows[0]['db']);
    }
}

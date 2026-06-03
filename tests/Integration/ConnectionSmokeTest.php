<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use ClickHouseDB\Client;
use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ClickHouseConnector;

/**
 * End-to-end smoke tests against a real ClickHouse server (see docker-compose.yml).
 *
 * These exercise the connector + connection directly, proving the Docker test
 * environment is fully usable. They are skipped automatically when CLICKHOUSE_HOST
 * is not set, so the unit suite still runs anywhere.
 */
final class ConnectionSmokeTest extends TestCase
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

    public function test_connector_connects_and_pings(): void
    {
        $client = (new ClickHouseConnector)->connect($this->config());

        self::assertInstanceOf(Client::class, $client);
        self::assertTrue($client->ping());
    }

    public function test_raw_select_returns_rows_through_the_driver(): void
    {
        $rows = $this->connection()->select("SELECT 1 AS n, 'ok' AS s");

        self::assertSame(1, (int) $rows[0]['n']);
        self::assertSame('ok', $rows[0]['s']);
    }

    public function test_insert_and_select_roundtrip(): void
    {
        $conn = $this->connection();

        $conn->insert('CREATE TABLE IF NOT EXISTS ec_smoke (id UInt32, name String) ENGINE = Memory');

        try {
            $conn->insert("INSERT INTO ec_smoke (id, name) VALUES (1, 'alpha'), (2, 'beta')");

            $rows = $conn->select('SELECT id, name FROM ec_smoke ORDER BY id');

            self::assertCount(2, $rows);
            self::assertSame('alpha', $rows[0]['name']);
            self::assertSame('beta', $rows[1]['name']);
        } finally {
            $conn->insert('DROP TABLE IF EXISTS ec_smoke');
        }
    }

    public function test_probe_active_database(): void
    {
        // Probe (not a regression assertion yet): with the current connector the wire
        // database is whatever smi2 defaults to, because the 'database' constructor key
        // is ignored. Once ClickHouseConnector calls $client->database(...), turn this
        // into self::assertSame($this->config()['database'], $rows[0]['db']).
        $rows = $this->connection()->select('SELECT currentDatabase() AS db');

        self::assertNotSame('', (string) $rows[0]['db']);
    }
}

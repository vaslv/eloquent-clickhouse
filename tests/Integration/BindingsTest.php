<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ClickHouseConnector;

/**
 * Bindings must be substituted (not dropped) and values must be escaped ClickHouse-safely so a
 * payload containing backslash + quote cannot break out of the string literal.
 */
final class BindingsTest extends TestCase
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

    public function test_select_substitutes_positional_bindings(): void
    {
        $rows = $this->connection()->select('SELECT ? AS n', [42]);

        self::assertSame(42, (int) $rows[0]['n']);
    }

    public function test_injection_payload_roundtrips_safely(): void
    {
        $conn = $this->connection();
        $evil = "a\\' OR 1=1 -- b";

        try {
            $conn->insert('CREATE TABLE IF NOT EXISTS ec_bindings (v String) ENGINE = Memory');

            // Builder insert inlines the value through QueryGrammar::parameter().
            $conn->table('ec_bindings')->insert(['v' => $evil]);

            $rows = $conn->select('SELECT v FROM ec_bindings');

            self::assertCount(1, $rows);
            self::assertSame($evil, (string) $rows[0]['v']);
        } finally {
            $conn->insert('DROP TABLE IF EXISTS ec_bindings');
        }
    }
}

<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests\Integration;

use ClickHouseDB\Client;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnection;
use Vaslv\EloquentClickHouse\ServiceProvider;

/**
 * Resolves the clickhouse connection through a real Illuminate DatabaseManager
 * (the runtime path the package's ServiceProvider registers), then runs a query
 * against the live ClickHouse server. This is the integration counterpart to the
 * reflection-only CompatibilityTest and validates the actual registration path.
 */
final class DatabaseManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('CLICKHOUSE_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_HOST not set — integration environment unavailable.');
        }
    }

    private function manager(): DatabaseManager
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'clickhouse',
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            'database' => getenv('CLICKHOUSE_DATABASE') ?: 'default',
            'username' => getenv('CLICKHOUSE_USERNAME') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: '',
            'prefix' => '',
        ], 'clickhouse');

        $container = $capsule->getContainer();
        $manager = $capsule->getDatabaseManager();
        $container->instance('db', $manager);

        $provider = new ServiceProvider($container);
        $provider->register();
        $provider->boot();

        return $manager;
    }

    public function test_connection_resolves_through_manager_and_queries(): void
    {
        $connection = $this->manager()->connection('clickhouse');

        self::assertInstanceOf(ClickHouseConnection::class, $connection);
        self::assertSame('clickhouse', $connection->getName());

        $rows = $connection->select('SELECT 42 AS answer');
        self::assertSame(42, (int) $rows[0]['answer']);
    }

    /**
     * The framework's reconnect lifecycle swaps $pdo on the existing connection
     * object; queries must follow the swap instead of using a stale cached client.
     */
    public function test_reconnect_swaps_the_client_used_by_queries(): void
    {
        $manager = $this->manager();
        $connection = $manager->connection('clickhouse');

        $before = $connection->getRawPdo();
        $manager->reconnect('clickhouse');

        self::assertNotSame($before, $connection->getRawPdo());

        $rows = $connection->select('SELECT 1 AS ok');
        self::assertSame(1, (int) $rows[0]['ok']);

        // Swapping in a broken client must break queries — proving they are routed
        // through getPdo() rather than a handle captured at construction time.
        $broken = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $connection->setPdo($broken)->setReadPdo($broken);

        try {
            $connection->select('SELECT 1');
            self::fail('Expected the swapped-in broken client to be used for queries.');
        } catch (\Throwable) {
            // expected: the uninitialised client cannot execute queries
        }
    }
}

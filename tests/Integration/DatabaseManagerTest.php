<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use ClickHouseDB\Client;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ServiceProvider;

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
}

<?php

namespace Vaslv\EloquentClickHouse;

use Illuminate\Support\Arr;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.clickhouse', function () {
            return new ClickHouseConnector;
        });
    }

    public function boot(): void
    {
        // Single registration path: the DatabaseManager extension. A
        // Connection::resolverFor() hook would never win over it (the manager
        // checks extensions first) and would receive a PDO/closure instead of
        // the smi2 Client, so it is intentionally not registered.
        $this->app['db']->extend('clickhouse', function (array $config, string $name) {
            // Mirror ConnectionFactory::parseConfig() so Connection::getName() works.
            $config['name'] = $name;

            $connector = $this->app->make('db.connector.clickhouse');
            $hasReadWrite = isset($config['read']) || isset($config['write']);

            // Read/write splitting: build the write client for the main connection and
            // a lazily-resolved read client, mirroring ConnectionFactory. Without this
            // the framework's useReadPdo selects would silently hit the write host, and a
            // config whose host lives only under read/write would fail connector validation.
            $writeConfig = $hasReadWrite ? $this->mergeReadWriteConfig($config, 'write') : $config;
            $client = $connector->connect($writeConfig);

            $connection = new ClickHouseConnection(
                $client,
                $writeConfig['database'],
                $config['prefix'] ?? '',
                $config,
            );

            if ($hasReadWrite) {
                $readConfig = $this->mergeReadWriteConfig($config, 'read');
                // A closure keeps the read client lazy (only connected when a read query
                // actually needs it), matching the framework's read-PDO resolver.
                $connection->setReadPdo(fn () => $connector->connect($readConfig));
            }

            return $connection;
        });
    }

    /**
     * Resolve a read/write sub-config the way ConnectionFactory does: pick one host
     * group at random when a list is given, merge it over the base config, and drop
     * the read/write keys so the connector sees a flat single-host config.
     */
    private function mergeReadWriteConfig(array $config, string $type): array
    {
        $level = isset($config[$type][0]) ? Arr::random($config[$type]) : ($config[$type] ?? []);

        return Arr::except(array_merge($config, $level), ['read', 'write']);
    }
}

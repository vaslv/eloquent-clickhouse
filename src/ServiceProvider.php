<?php

namespace Timeleads\EloquentClickHouse;

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

            $connection = $this->app->make('db.connector.clickhouse')->connect($config);

            return new ClickHouseConnection($connection, $config['database'], $config['prefix'] ?? '', $config);
        });
    }
}

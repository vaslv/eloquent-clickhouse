<?php

namespace Timeleads\EloquentClickHouse;

use Illuminate\Database\Connection;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.clickhouse', function ($app) {
            return new ClickHouseConnector;
        });

        Connection::resolverFor('clickhouse', function ($connection, $database, $prefix, $config) {
            return new ClickHouseConnection($connection, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        $this->app->extend('db', function ($factory, $app) {
            $factory->extend('clickhouse', function ($config) use ($app) {
                $connector = $app->make('db.connector.clickhouse');
                $connection = $connector->connect($config);

                return new ClickHouseConnection($connection, $config['database'], $config['prefix'] ?? '', $config);
            });

            return $factory;
        });
    }
}

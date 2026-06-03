<?php

namespace Timeleads\EloquentClickHouse;

use ClickHouseDB\Client;
use Exception;
use Illuminate\Database\Connectors\ConnectorInterface;

class ClickHouseConnector implements ConnectorInterface
{
    /**
     * @throws Exception
     */
    public function connect(array $config): Client
    {
        if (! isset($config['host'], $config['port'], $config['database'], $config['username'], $config['password'])) {
            throw new Exception('Missing required ClickHouse configuration parameters.');
        }

        $client = new Client([
            'host' => $config['host'],
            'port' => $config['port'],
            'username' => $config['username'],
            'password' => $config['password'],
        ]);

        // The smi2 client ignores a 'database' connect param and defaults to 'default';
        // the database must be selected explicitly via settings.
        $client->database($config['database']);

        if (! empty($config['settings'])) {
            $client->settings()->apply($config['settings']);
        }

        if (! $client->ping()) {
            throw new Exception('Could not connect to ClickHouse database.');
        }

        return $client;
    }
}

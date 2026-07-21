<?php

namespace Vaslv\EloquentClickHouse;

use ClickHouseDB\Client;
use Exception;
use Illuminate\Database\Connectors\ConnectorInterface;

class ClickHouseConnector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     *
     * @throws Exception
     */
    public function connect(array $config): Client
    {
        if (! isset($config['host'], $config['port'], $config['database'], $config['username'], $config['password'])) {
            throw new Exception('Missing required ClickHouse configuration parameters.');
        }

        $client = new Client($this->clientParams($config));

        // The smi2 client ignores a 'database' connect param and defaults to 'default';
        // the database must be selected explicitly via settings.
        $client->database($config['database']);

        if (isset($config['timeout'])) {
            $client->setTimeout((int) $config['timeout']);
        }

        if (isset($config['connect_timeout'])) {
            $client->setConnectTimeOut((float) $config['connect_timeout']);
        }

        if (! empty($config['settings'])) {
            $client->settings()->apply($config['settings']);
        }

        // Reachability check only: the smi2 ping() request bypasses sslCA/curl_options,
        // so it never validates the server certificate even when 'verify' is enabled.
        if (! $client->ping()) {
            throw new Exception('Could not connect to ClickHouse database.');
        }

        return $client;
    }

    /**
     * Map the Laravel connection config onto smi2 Client constructor params.
     *
     * The smi2 client defaults to CURLOPT_SSL_VERIFYPEER=false / CURLOPT_SSL_VERIFYHOST=0
     * even over HTTPS, so 'https' => true verifies the server certificate by default
     * here; opt out with 'verify' => false. A CA bundle can be given as 'sslCA' (the
     * smi2 spelling) or 'ssl_ca', and 'curl_options' wins over the derived defaults.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function clientParams(array $config): array
    {
        $params = [
            'host' => $config['host'],
            'port' => $config['port'],
            'username' => $config['username'],
            'password' => $config['password'],
        ];

        $curlOptions = [];

        if (! empty($config['https'])) {
            $params['https'] = true;

            $curlOptions = ($config['verify'] ?? true)
                ? [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]
                : [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
        }

        $sslCa = $config['sslCA'] ?? $config['ssl_ca'] ?? null;

        if ($sslCa) {
            // Also flips CURLOPT_SSL_VERIFYPEER back on inside the client.
            $params['sslCA'] = $sslCa;
        }

        $curlOptions = ($config['curl_options'] ?? []) + $curlOptions;

        if ($curlOptions !== []) {
            $params['curl_options'] = $curlOptions;
        }

        return $params;
    }
}

<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests;

use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnector;

/**
 * Config-mapping assertions for the connector. connect() needs a live server (it pings),
 * but the TLS posture is fully determined by clientParams(), which is wire-free.
 */
final class ConnectorTest extends TestCase
{
    private const BASE = [
        'host' => '127.0.0.1',
        'port' => 8443,
        'database' => 'default',
        'username' => 'default',
        'password' => '',
    ];

    private function params(array $overrides): array
    {
        return (new ClickHouseConnector)->clientParams($overrides + self::BASE);
    }

    public function test_plain_http_passes_no_tls_params(): void
    {
        $params = $this->params([]);

        self::assertArrayNotHasKey('https', $params);
        self::assertArrayNotHasKey('curl_options', $params);
        self::assertArrayNotHasKey('sslCA', $params);
    }

    public function test_https_verifies_certificates_by_default(): void
    {
        $params = $this->params(['https' => true]);

        self::assertTrue($params['https']);
        self::assertTrue($params['curl_options'][CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $params['curl_options'][CURLOPT_SSL_VERIFYHOST]);
    }

    public function test_verify_false_disables_certificate_checks(): void
    {
        $params = $this->params(['https' => true, 'verify' => false]);

        self::assertFalse($params['curl_options'][CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(0, $params['curl_options'][CURLOPT_SSL_VERIFYHOST]);
    }

    public function test_ssl_ca_is_forwarded_under_the_smi2_spelling(): void
    {
        self::assertSame('/etc/ca.pem', $this->params(['sslCA' => '/etc/ca.pem'])['sslCA']);
        self::assertSame('/etc/ca.pem', $this->params(['ssl_ca' => '/etc/ca.pem'])['sslCA']);
    }

    public function test_user_curl_options_win_over_derived_defaults(): void
    {
        $params = $this->params([
            'https' => true,
            'curl_options' => [CURLOPT_SSL_VERIFYHOST => 0],
        ]);

        self::assertSame(0, $params['curl_options'][CURLOPT_SSL_VERIFYHOST]);
        self::assertTrue($params['curl_options'][CURLOPT_SSL_VERIFYPEER]);
    }
}
